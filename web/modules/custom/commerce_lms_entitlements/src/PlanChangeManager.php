<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;

/** Coordinates PayPal revision approval without changing access prematurely. */
final class PlanChangeManager {

  public function __construct(private Connection $database, private EntityTypeManagerInterface $entityTypeManager, private TimeInterface $time, private EntitlementManager $entitlements, private PayPalSubscriptionOperations $operations) {}

  /** Starts a base/VIP revision and returns the PayPal approval URL. */
  public function request(int $eid, int $purchaser_uid): string {
    $entitlement = $this->entitlements->load($eid);
    if (!$entitlement || (int) $entitlement['purchaser_uid'] !== $purchaser_uid) {
      throw new \DomainException('You do not own this subscription.');
    }
    if ($entitlement['purchase_type'] !== 'recurring' || $entitlement['status'] !== 'active' || empty($entitlement['paypal_subscription_id'])) {
      throw new \DomainException('Only an active recurring subscription can change VIP tier.');
    }
    if ($this->entitlements->pendingPlanChange($eid)) {
      throw new \DomainException('This subscription already has a pending tier change.');
    }
    $offer = $this->entityTypeManager->getStorage('commerce_lms_offer')->load($entitlement['offer_id']);
    if (!$offer || !$offer->isVipEnabled()) {
      throw new \DomainException('VIP tier changes are not configured for this offer.');
    }
    [$gateway, $remote] = $this->gatewayAndRemote($entitlement);
    $to_tier = !empty($entitlement['vip_active']) ? 'base' : 'vip';
    if ((string) $gateway->id() === $offer->getPayPalSandboxGatewayId()) {
      $target_plan = $to_tier === 'vip' ? $offer->getPayPalSandboxVipPlanId() : $offer->getPayPalSandboxPlanId();
    }
    elseif ((string) $gateway->id() === $offer->getPayPalLiveGatewayId()) {
      $target_plan = $to_tier === 'vip' ? $offer->getPayPalLiveVipPlanId() : $offer->getPayPalLivePlanId();
    }
    else {
      throw new \DomainException('The subscription order gateway no longer matches this offer.');
    }
    if ($target_plan === '') {
      throw new \DomainException('The target PayPal plan is not configured.');
    }
    $source_plan = $this->operations->fetchPlan($gateway, (string) $remote['plan_id']);
    $target_details = $this->operations->fetchPlan($gateway, $target_plan);
    if (($target_details['status'] ?? '') !== 'ACTIVE' || ($source_plan['product_id'] ?? '') !== ($target_details['product_id'] ?? '')) {
      throw new \DomainException('The target plan must be active and belong to the subscription’s current PayPal product.');
    }
    $source_cycle = $this->regularCycle($source_plan);
    $target_cycle = $this->regularCycle($target_details);
    if (($source_cycle['frequency'] ?? []) !== ($target_cycle['frequency'] ?? [])) {
      throw new \DomainException('The current and target PayPal plans must use the same billing cadence.');
    }
    $effective = !empty($remote['billing_info']['next_billing_time']) ? strtotime((string) $remote['billing_info']['next_billing_time']) : FALSE;
    if (!$effective) {
      throw new \RuntimeException('PayPal did not provide the next billing time for this subscription.');
    }
    $token = bin2hex(random_bytes(32));
    $now = $this->time->getRequestTime();
    $id = $this->database->insert('commerce_lms_plan_change')->fields([
      'eid' => $eid,
      'from_tier' => !empty($entitlement['vip_active']) ? 'vip' : 'base',
      'to_tier' => $to_tier,
      'from_plan_id' => (string) ($remote['plan_id'] ?? $entitlement['paypal_plan_id']),
      'target_plan_id' => $target_plan,
      'state_hash' => hash('sha256', $token),
      'status' => 'approval_pending',
      'requested' => $now,
      'effective' => $effective,
      'changed' => $now,
    ])->execute();
    try {
      $return = Url::fromRoute('commerce_lms_entitlements.plan_change_return', ['token' => $token], ['absolute' => TRUE])->toString();
      $cancel = Url::fromRoute('commerce_lms_entitlements.plan_change_cancel', ['token' => $token], ['absolute' => TRUE])->toString();
      return $this->operations->revise($gateway, $entitlement['paypal_subscription_id'], $target_plan, $return, $cancel, 'commerce-lms-tier-' . $id);
    }
    catch (\Throwable $e) {
      $this->mark((int) $id, 'failed', $e->getMessage());
      throw $e;
    }
  }

  /** Verifies a PayPal return and records approval; billing activates later. */
  public function complete(string $token, int $purchaser_uid, bool $cancelled): array {
    $change = $this->loadByToken($token);
    if (!$change || $change['status'] !== 'approval_pending') {
      throw new \DomainException('This tier-change link is invalid or has already been used.');
    }
    $entitlement = $this->entitlements->load((int) $change['eid']);
    if (!$entitlement || (int) $entitlement['purchaser_uid'] !== $purchaser_uid) {
      throw new \DomainException('You do not own this subscription.');
    }
    if ($cancelled) {
      $this->mark((int) $change['id'], 'abandoned');
      return $change;
    }
    [, $remote] = $this->gatewayAndRemote($entitlement);
    if ((string) ($remote['id'] ?? '') !== (string) $entitlement['paypal_subscription_id'] || (string) ($remote['plan_id'] ?? '') !== (string) $change['target_plan_id']) {
      // PayPal's return can precede propagation to the subscription detail
      // endpoint. Leave the transition pending so a verified webhook or the
      // next reconciliation fetch can confirm it without losing the request.
      throw new \RuntimeException('PayPal approval is still being verified. Your existing access remains unchanged.');
    }
    $this->mark((int) $change['id'], 'approved');
    return $change;
  }

  /** Loads the configured gateway and authoritative subscription. */
  private function gatewayAndRemote(array $entitlement): array {
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($entitlement['order_id']);
    $gateway_id = $order?->get('payment_gateway')->target_id;
    $gateway = $gateway_id ? $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($gateway_id) : NULL;
    if (!$gateway) {
      throw new \RuntimeException('The subscription payment gateway no longer exists.');
    }
    return [$gateway, $this->operations->fetchSubscription($gateway, $entitlement['paypal_subscription_id'])];
  }

  private function loadByToken(string $token): ?array {
    $row = $this->database->select('commerce_lms_plan_change', 'p')->fields('p')->condition('state_hash', hash('sha256', $token))->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  /** Returns the only regular billing cycle or rejects an unsafe plan. */
  private function regularCycle(array $plan): array {
    $cycles = array_values(array_filter($plan['billing_cycles'] ?? [], static fn(array $cycle): bool => ($cycle['tenure_type'] ?? '') === 'REGULAR'));
    if (count($cycles) !== 1) {
      throw new \DomainException('PayPal tier plans must each contain exactly one regular billing cycle.');
    }
    return $cycles[0];
  }

  private function mark(int $id, string $status, ?string $error = NULL): void {
    $this->database->update('commerce_lms_plan_change')->fields(['status' => $status, 'error' => $error, 'changed' => $this->time->getRequestTime()])->condition('id', $id)->execute();
  }

}
