<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_lms_entitlements\Entity\LmsSubscriptionCampaign;
use Drupal\commerce_price\Calculator;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Coordinates the entitlement state machine and Group membership ownership.
 *
 * This is the module's central service. It is intentionally the only place
 * that grants or revokes LMS Class memberships. Controllers, checkout hooks,
 * event subscribers, and queue workers supply an input event; this service
 * verifies the relevant local invariants and performs the resulting state
 * transition. Keeping that policy here makes retries safe and prevents a
 * webhook handler from accidentally treating its payload as billing truth.
 *
 * See docs/ARCHITECTURE.md for the table layout and lifecycle diagrams.
 */
final class EntitlementManager {
  public function __construct(
    private Connection $database,
    private EntityTypeManagerInterface $entityTypeManager,
    private EntitlementMembershipManager $membershipManager,
    private ConfigFactoryInterface $configFactory,
    private QueueFactory $queue,
    private TimeInterface $time,
    private LoggerInterface $logger,
    private object $sdkFactory,
  ) {}

  /**
   * Resolves and validates the one offer permitted for an LMS checkout order.
   *
   * @throws \DomainException
   *   If the cart is not exactly one variation at quantity one, the variation
   *   has no unique offer, or an offer target no longer matches LMS structure.
   */
  public function offerForOrder(object $order): object {
    $items = $order->getItems();
    if (count($items) !== 1 || Calculator::compare($items[0]->getQuantity(), '1') !== 0) {
      throw new \DomainException('An LMS offer checkout must contain exactly one item at quantity one.');
    }
    $offers = $this->entityTypeManager->getStorage('commerce_lms_offer')->loadByProperties(['variation_id' => (int) $items[0]->getPurchasedEntityId()]);
    if (count($offers) !== 1) { throw new \DomainException('The purchased variation has no unique LMS offer.'); }
    $offer = reset($offers);
    $this->validateTargets($offer);
    return $offer;
  }

  /**
   * Creates one pending entitlement from order-scoped learner data.
   *
   * The unique `order_id` makes this idempotent. A retry returns the original
   * row rather than granting a second bundle or creating a second audit trail.
   */
  public function ensureEntitlement(object $order, object $offer, ?SubscriptionPlanSelection $selection = NULL): array {
    $vip_selected = $selection?->vip
      ?? ($offer->getPurchaseType() === 'recurring' && $offer->isVipEnabled() && (bool) $order->getData('commerce_lms_vip_selected'));
    $plan_id = $selection?->planId
      ?? ($offer->getPurchaseType() === 'recurring' ? ($vip_selected ? $offer->getActivePayPalVipPlanId() : $offer->getActivePayPalPlanId()) : NULL);
    $campaign_values = [
      'subscription_campaign_id' => $selection?->campaignId,
      'promotion_uuid' => $selection?->promotionUuid,
      'coupon_uuid' => $selection?->couponUuid,
    ];
    if ($existing = $this->loadByOrder((int) $order->id())) {
      // A checkout rebuild may change the bump before PayPal approval. It is
      // safe to refresh only a still-unlinked pending row; billing-linked rows
      // can change tier only through the revision workflow.
      if ($existing['status'] === 'pending' && empty($existing['paypal_subscription_id'])) {
        $this->update((int) $existing['eid'], [
          'paypal_plan_id' => $plan_id,
          'vip_selected' => $vip_selected ? 1 : 0,
        ] + $campaign_values);
        return $this->load((int) $existing['eid']);
      }
      return $existing;
    }
    $learner = $order->getData('commerce_lms_learner') ?: [];
    if (empty($learner['uid']) && empty($learner['invitation_id'])) { throw new \DomainException('A learner must be selected before payment approval.'); }
    $now = $this->time->getRequestTime();
    $this->database->insert('commerce_lms_entitlement')->fields([
      'offer_id' => $offer->id(), 'purchase_type' => $offer->getPurchaseType(), 'purchaser_uid' => (int) $order->getCustomerId(),
      'learner_uid' => !empty($learner['uid']) ? (int) $learner['uid'] : NULL, 'invitation_id' => $learner['invitation_id'] ?? NULL,
      'order_id' => (int) $order->id(), 'status' => 'pending',
      'paypal_plan_id' => $plan_id,
      'vip_selected' => $vip_selected ? 1 : 0,
      'vip_active' => 0,
      'subscription_campaign_id' => $campaign_values['subscription_campaign_id'],
      'promotion_uuid' => $campaign_values['promotion_uuid'],
      'coupon_uuid' => $campaign_values['coupon_uuid'],
      'created' => $now, 'changed' => $now,
    ])->execute();
    return $this->loadByOrder((int) $order->id());
  }

  public function load(int $eid): ?array { $row = $this->database->select('commerce_lms_entitlement', 'e')->fields('e')->condition('eid', $eid)->execute()->fetchAssoc(); return $row ?: NULL; }
  public function loadByOrder(int $order_id): ?array { $row = $this->database->select('commerce_lms_entitlement', 'e')->fields('e')->condition('order_id', $order_id)->execute()->fetchAssoc(); return $row ?: NULL; }
  public function loadByPayPalSubscription(string $id): ?array { $row = $this->database->select('commerce_lms_entitlement', 'e')->fields('e')->condition('paypal_subscription_id', $id)->execute()->fetchAssoc(); return $row ?: NULL; }
  public function update(int $eid, array $values): void { $values['changed'] = $this->time->getRequestTime(); $this->database->update('commerce_lms_entitlement')->fields($values)->condition('eid', $eid)->execute(); }

  /**
   * Copies contributed checkout's PayPal subscription ID onto the local row.
   *
   * It also wakes verified events that arrived during the approval/order-save
   * race, preserving them instead of treating a temporary missing link as an
   * unknown subscription.
   */
  public function linkPayPalSubscriptionFromOrder(object $order): void {
    $id = $order->getData('paypal_subscription_id');
    $entitlement = $this->loadByOrder((int) $order->id());
    if (!$entitlement) {
      return;
    }

    $values = [];
    if (is_string($id) && $id !== '' && $id !== $entitlement['paypal_subscription_id']) {
      $values['paypal_subscription_id'] = $id;
    }
    $purchaser_uid = (int) $order->getCustomerId();
    if ($purchaser_uid > 0 && $purchaser_uid !== (int) $entitlement['purchaser_uid']) {
      $values['purchaser_uid'] = $purchaser_uid;
    }
    if ($values) {
      $this->update((int) $entitlement['eid'], $values);
    }
    if (!is_string($id) || $id === '') {
      return;
    }
    // A webhook can legitimately arrive between buyer approval and this order
    // update. Requeue those persisted events now that they can be associated.
    foreach ($this->database->select('commerce_lms_entitlement_event', 'e')->fields('e', ['event_id'])->condition('paypal_subscription_id', $id)->condition('status', ['queued', 'failed'], 'IN')->execute()->fetchCol() as $event_id) {
      $this->queue->get('commerce_lms_entitlements_webhook')->createItem(['event_id' => $event_id]);
    }
  }

  /**
   * Activates a lifetime offer after a completed normal Commerce payment.
   *
   * Recurring offers deliberately do not pass through this path: their
   * recurring lifecycle is driven by a current PayPal subscription response.
   */
  public function syncCompletedPayment(object $payment): void {
    if (!$payment->getOrder() || $payment->getState()->value !== 'completed') {
      return;
    }
    $order = $payment->getOrder();
    if (!$order->isPaid()) {
      return;
    }

    $this->activatePaidLifetimeOrder($order, $payment);
  }

  /**
   * Activates a fully paid lifetime order after Commerce refreshes its totals.
   *
   * Commerce's payment order updater can defer the aggregate `total_paid`
   * recalculation until after the payment entity hook has run. The subsequent
   * order save calls this method, which finds a completed payment belonging to
   * this order and applies the same activation checks.
   */
  public function syncPaidLifetimeOrder(object $order): void {
    if (!$order->isPaid()) {
      return;
    }

    try {
      $offer = $this->offerForOrder($order);
    }
    catch (\DomainException) {
      return;
    }
    if ($offer->getPurchaseType() !== 'lifetime') {
      return;
    }

    $payments = $this->entityTypeManager
      ->getStorage('commerce_payment')
      ->loadByProperties([
        'order_id' => (int) $order->id(),
        'state' => 'completed',
      ]);
    usort(
      $payments,
      static fn (object $a, object $b): int => (int) $b->id() <=> (int) $a->id(),
    );

    foreach ($payments as $payment) {
      if ($this->paymentBelongsToOrder($payment, $order)
        && (string) ($payment->getPaymentGateway()->id() ?? '') === $offer->getPaymentGatewayId()
      ) {
        $this->activatePaidLifetimeOrder($order, $payment, $offer);
        return;
      }
    }
  }

  /**
   * Performs the final, idempotent lifetime-entitlement activation.
   */
  private function activatePaidLifetimeOrder(
    object $order,
    object $payment,
    ?object $offer = NULL,
  ): void {
    if (!$order->isPaid()
      || $payment->getState()->value !== 'completed'
      || !$this->paymentBelongsToOrder($payment, $order)) {
      return;
    }

    if (!$offer) {
      try {
        $offer = $this->offerForOrder($order);
      }
      catch (\DomainException) {
        return;
      }
    }
    if ($offer->getPurchaseType() !== 'lifetime') {
      return;
    }

    $gateway_id = (string) ($payment->getPaymentGateway()->id() ?? '');
    if ($gateway_id !== $offer->getPaymentGatewayId()) {
      $this->logger->warning('Lifetime order @order completed through unexpected gateway @gateway.', [
        '@order' => $order->id(),
        '@gateway' => $gateway_id ?: 'none',
      ]);
      return;
    }

    $entitlement = $this->ensureEntitlement($order, $offer);
    if ($entitlement['status'] === 'active') {
      return;
    }

    $this->update((int) $entitlement['eid'], [
      'payment_id' => (int) $payment->id(),
      'initial_capture_id' => $payment->getRemoteId(),
      'status' => 'active',
      'activated' => $this->time->getRequestTime(),
    ]);
    $this->grant($this->load((int) $entitlement['eid']));
  }

  /**
   * Confirms that a payment's order reference matches the candidate order.
   */
  private function paymentBelongsToOrder(object $payment, object $order): bool {
    $payment_order = $payment->getOrder();
    return $payment_order && (int) $payment_order->id() === (int) $order->id();
  }

  /**
   * Records a signature-verified PayPal event once and enqueues asynchronous work.
   *
   * A database uniqueness exception means the event ID has already been
   * accepted, which is a normal duplicate delivery rather than an error.
   */
  public function queueEvent(array $event): bool {
    $event_id = (string) ($event['id'] ?? '');
    if ($event_id === '') { throw new \InvalidArgumentException('PayPal event is missing its ID.'); }
    $subscription_id = $this->extractPayPalSubscriptionId($event);
    $now = $this->time->getRequestTime();
    try { $this->database->insert('commerce_lms_entitlement_event')->fields(['event_id' => $event_id, 'paypal_subscription_id' => $subscription_id ?: NULL, 'event_type' => (string) ($event['event_type'] ?? ''), 'payload' => json_encode($event, JSON_THROW_ON_ERROR), 'created' => $now, 'changed' => $now])->execute(); }
    catch (\Exception) { return FALSE; }
    $this->queue->get('commerce_lms_entitlements_webhook')->createItem(['event_id' => $event_id]);
    return TRUE;
  }

  /** Returns whether a PayPal event identifies subscription state or payment. */
  public function isSubscriptionEvent(array $event): bool {
    $event_type = (string) ($event['event_type'] ?? '');
    if (str_starts_with($event_type, 'BILLING.SUBSCRIPTION.')) {
      return TRUE;
    }
    $resource = is_array($event['resource'] ?? NULL) ? $event['resource'] : [];
    if (!empty($resource['billing_agreement_id']) || !empty($resource['subscription_id'])) {
      return TRUE;
    }
    try {
      $payload = json_decode((string) ($event['payload'] ?? ''), TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($payload) && $this->isSubscriptionEvent($payload);
    }
    catch (\JsonException) {
      return FALSE;
    }
  }

  public function event(string $event_id): ?array { $row = $this->database->select('commerce_lms_entitlement_event', 'e')->fields('e')->condition('event_id', $event_id)->execute()->fetchAssoc(); return $row ?: NULL; }

  /**
   * Resolves and, when necessary, repairs an event's PayPal subscription ID.
   *
   * This also makes already-persisted payment events compatible with the
   * corrected extraction rules. Earlier module versions stored the payment
   * transaction's resource ID instead of its parent billing-agreement ID.
   */
  public function resolveEventSubscriptionId(array $stored_event): string {
    $subscription_id = (string) ($stored_event['paypal_subscription_id'] ?? '');
    try {
      $payload = json_decode((string) ($stored_event['payload'] ?? ''), TRUE, 512, JSON_THROW_ON_ERROR);
      if (is_array($payload)) {
        $extracted_id = $this->extractPayPalSubscriptionId($payload);
        if ($extracted_id !== '' && $extracted_id !== $subscription_id) {
          $subscription_id = $extracted_id;
          $this->database->update('commerce_lms_entitlement_event')
            ->fields([
              'paypal_subscription_id' => $subscription_id,
              'changed' => $this->time->getRequestTime(),
            ])
            ->condition('event_id', $stored_event['event_id'])
            ->execute();
        }
      }
    }
    catch (\JsonException) {
      // The worker will report the missing ID or invalid payload when it
      // attempts to process the event. Do not hide the original failure.
    }
    return $subscription_id;
  }

  public function markEvent(string $event_id, string $status): void { $this->database->update('commerce_lms_entitlement_event')->fields(['status' => $status, 'changed' => $this->time->getRequestTime()])->condition('event_id', $event_id)->execute(); }

  /** Extracts a subscription ID from supported PayPal webhook shapes. */
  private function extractPayPalSubscriptionId(array $event): string {
    $resource = is_array($event['resource'] ?? NULL) ? $event['resource'] : [];
    $event_type = (string) ($event['event_type'] ?? '');
    // Subscription lifecycle resources use their own ID. Payment-sale
    // resources use a transaction ID as `id` and expose the parent
    // subscription as `billing_agreement_id`.
    return str_starts_with($event_type, 'BILLING.SUBSCRIPTION.')
      ? (string) ($resource['id'] ?? '')
      : (string) ($resource['billing_agreement_id'] ?? $resource['subscription_id'] ?? '');
  }

  /**
   * Applies authoritative PayPal detail to local status and Group access.
   *
   * The webhook worker and reconciliation worker both call this method only
   * after `GET /v1/billing/subscriptions/{id}`. Cancellation is special: a
   * cancelled subscription can retain access through the paid-through time.
   */
  public function applyRemoteSubscription(array $entitlement, array $remote): void {
    $status = strtoupper((string) ($remote['status'] ?? ''));
    $local = ['ACTIVE' => 'active', 'SUSPENDED' => 'suspended', 'CANCELLED' => 'cancelled', 'EXPIRED' => 'expired'][$status] ?? 'pending';
    $through = !empty($remote['billing_info']['next_billing_time']) ? strtotime($remote['billing_info']['next_billing_time']) ?: NULL : NULL;
    $values = ['status' => $local, 'access_through' => $through];
    $remote_plan_id = (string) ($remote['plan_id'] ?? '');
    $pending_change = $this->pendingPlanChange((int) $entitlement['eid']);
    $allowed_plan_ids = array_filter([(string) ($entitlement['paypal_plan_id'] ?? '')]);
    if ($pending_change) {
      $allowed_plan_ids[] = (string) $pending_change['target_plan_id'];
    }
    if ($remote_plan_id !== '' && !in_array($remote_plan_id, $allowed_plan_ids, TRUE)) {
      $this->logger->error('PayPal plan mismatch for entitlement @eid: expected @expected, received @remote.', [
        '@eid' => $entitlement['eid'],
        '@expected' => implode(' or ', $allowed_plan_ids),
        '@remote' => $remote_plan_id,
      ]);
      throw new \DomainException('PayPal returned a plan that is not authorized for this entitlement.');
    }
    if ($pending_change && $pending_change['status'] === 'approval_pending' && $remote_plan_id === (string) $pending_change['target_plan_id']) {
      $this->database->update('commerce_lms_plan_change')
        ->fields(['status' => 'approved', 'changed' => $this->time->getRequestTime()])
        ->condition('id', $pending_change['id'])
        ->execute();
    }
    if ($remote_plan_id !== '') {
      $values['paypal_plan_id'] = $remote_plan_id;
    }
    $offer = $this->entityTypeManager->getStorage('commerce_lms_offer')->load($entitlement['offer_id']);
    if ($offer && $remote_plan_id !== '' && $remote_plan_id === $this->vipPlanForEntitlement($entitlement, $offer)) {
      $values['vip_selected'] = 1;
      // Initial VIP subscriptions activate immediately. Revised upgrades are
      // finalized separately only after their first VIP-priced payment.
      if (!$this->pendingPlanChange((int) $entitlement['eid'])) {
        $values['vip_active'] = 1;
      }
    }
    if ($local === 'active' && empty($entitlement['activated'])) { $values['activated'] = $this->time->getRequestTime(); }
    $capture = $remote['billing_info']['last_payment']['transaction_id'] ?? NULL;
    if ($capture && empty($entitlement['initial_capture_id'])) { $values['initial_capture_id'] = $capture; }
    $this->update((int) $entitlement['eid'], $values);
    $this->finalizeDuePlanChange((int) $entitlement['eid'], $remote);
    $current = $this->load((int) $entitlement['eid']);
    if ($local === 'active') { $this->grant($current); }
    elseif (in_array($local, ['suspended', 'expired'], TRUE) || ($local === 'cancelled' && (!$through || $through <= $this->time->getRequestTime()))) { $this->revoke($current); }
  }

  /**
   * Reconciles expiry and nonterminal recurring records during cron.
   *
   * Remote failures are isolated and logged by entitlement so that one bad
   * gateway response cannot prevent unrelated expiry revocations.
   */
  public function reconcile(): void {
    $ids = $this->database->select('commerce_lms_entitlement', 'e')->fields('e', ['eid'])->condition('status', 'cancelled')->condition('access_through', $this->time->getRequestTime(), '<=')->execute()->fetchCol();
    foreach ($ids as $id) { if ($entitlement = $this->load((int) $id)) { $this->revoke($entitlement); } }
    // Reconcile current remote state too: webhook delivery is not assumed to be
    // perfect, and PayPal remains the billing authority for recurring offers.
    $ids = $this->database->select('commerce_lms_entitlement', 'e')->fields('e', ['eid'])->condition('purchase_type', 'recurring')->condition('paypal_subscription_id', NULL, 'IS NOT NULL')->condition('status', ['pending', 'active', 'suspended', 'cancelled'], 'IN')->execute()->fetchCol();
    foreach ($ids as $id) {
      try {
        $entitlement = $this->load((int) $id);
        $order = $entitlement ? $this->entityTypeManager->getStorage('commerce_order')->load($entitlement['order_id']) : NULL;
        $gateway_id = $order?->get('payment_gateway')->target_id;
        $gateway = $gateway_id ? $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($gateway_id) : NULL;
        if (!$entitlement || !$gateway) {
          throw new \RuntimeException('Missing entitlement order or payment gateway.');
        }
        $response = $this->sdkFactory->get($gateway->getPluginConfiguration())->getSubscription($entitlement['paypal_subscription_id']);
        $this->applyRemoteSubscription($entitlement, json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR));
      }
      catch (\Throwable $e) {
        $this->logger->error('Reconciliation failed for entitlement @eid: @message', ['@eid' => $id, '@message' => $e->getMessage()]);
      }
    }
  }

  /** Returns an unresolved PayPal plan revision for an entitlement. */
  public function pendingPlanChange(int $eid): ?array {
    $row = $this->database->select('commerce_lms_plan_change', 'p')
      ->fields('p')
      ->condition('eid', $eid)
      ->condition('status', ['approval_pending', 'approved'], 'IN')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /** Applies an approved tier at its first successfully paid billing boundary. */
  private function finalizeDuePlanChange(int $eid, array $remote): void {
    $change = $this->pendingPlanChange($eid);
    if (!$change || $change['status'] !== 'approved' || empty($change['effective'])) {
      return;
    }
    $last_payment = !empty($remote['billing_info']['last_payment']['time'])
      ? strtotime((string) $remote['billing_info']['last_payment']['time'])
      : FALSE;
    if (!$last_payment || $last_payment < (int) $change['effective']) {
      return;
    }
    $vip = $change['to_tier'] === 'vip';
    $this->update($eid, [
      'paypal_plan_id' => $change['target_plan_id'],
      'vip_selected' => $vip ? 1 : 0,
      'vip_active' => $vip ? 1 : 0,
    ]);
    $this->database->update('commerce_lms_plan_change')
      ->fields(['status' => 'effective', 'changed' => $this->time->getRequestTime()])
      ->condition('id', $change['id'])
      ->execute();
    if (!$vip && ($entitlement = $this->load($eid))) {
      $this->revokeBenefit($entitlement, 'vip');
    }
  }

  /** Resolves the VIP plan from the subscription's original gateway. */
  private function vipPlanForEntitlement(array $entitlement, object $offer): string {
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($entitlement['order_id']);
    $gateway_id = (string) ($order?->get('payment_gateway')->target_id ?? '');
    if (!empty($entitlement['subscription_campaign_id'])) {
      $campaign = $this->entityTypeManager->getStorage(LmsSubscriptionCampaign::ENTITY_TYPE_ID)->load($entitlement['subscription_campaign_id']);
      if ($campaign instanceof LmsSubscriptionCampaign) {
        if ($gateway_id !== '' && $gateway_id === $offer->getPayPalSandboxGatewayId()) {
          return $campaign->getPayPalPlanId($offer->id(), 'sandbox', TRUE);
        }
        if ($gateway_id !== '' && $gateway_id === $offer->getPayPalLiveGatewayId()) {
          return $campaign->getPayPalPlanId($offer->id(), 'live', TRUE);
        }
      }
    }
    if ($gateway_id !== '' && $gateway_id === $offer->getPayPalSandboxGatewayId()) {
      return $offer->getPayPalSandboxVipPlanId();
    }
    if ($gateway_id !== '' && $gateway_id === $offer->getPayPalLiveGatewayId()) {
      return $offer->getPayPalLiveVipPlanId();
    }
    return '';
  }

  /** Immediately removes only memberships that this entitlement created. */
  public function revokeImmediately(array $entitlement): void {
    $this->revoke($entitlement);
  }

  /** Returns TRUE while an entitlement is inside its 40 calendar-day guarantee. */
  public function isGuaranteeEligible(array $entitlement): bool {
    if (empty($entitlement['activated'])) {
      return FALSE;
    }
    $activated = (new \DateTimeImmutable('@' . $entitlement['activated']))->setTimezone(new \DateTimeZone('UTC'));
    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone(new \DateTimeZone('UTC'));
    return $now < $activated->modify('+40 days');
  }

  /** Creates a 30-day invitation; only a hash of the token is persisted. */
  public function createInvitation(string $email): array {
    $id = \Drupal::service('uuid')->generate(); $token = bin2hex(random_bytes(32)); $now = $this->time->getRequestTime();
    $this->database->insert('commerce_lms_entitlement_invitation')->fields(['id' => $id, 'email' => mb_strtolower($email), 'token_hash' => hash('sha256', $token), 'created' => $now, 'expires' => $now + 30 * 86400])->execute();
    return ['id' => $id, 'token' => $token];
  }
  /**
   * Loads an unclaimed, unexpired invitation by a presented plaintext token.
   *
   * The token is hashed before comparison; callers never receive the stored
   * hash and cannot use this method to enumerate valid invitation IDs.
   */
  public function invitation(string $token): ?array {
    $invite = $this->database->select('commerce_lms_entitlement_invitation', 'i')->fields('i')->condition('token_hash', hash('sha256', $token))->condition('expires', $this->time->getRequestTime(), '>')->isNull('claimed_uid')->execute()->fetchAssoc();
    return $invite ?: NULL;
  }
  /**
   * Binds all invitation-backed entitlements to the invited-email account.
   *
   * The email comparison prevents a forwarded claim link from assigning course
   * access to a different Drupal account. Already-active rows are granted as
   * soon as they acquire their learner UID.
   */
  public function claimInvitation(string $token, int $uid, string $email): bool {
    $invite = $this->invitation($token);
    if ($invite && mb_strtolower($email) !== $invite['email']) {
      return FALSE;
    }
    if (!$invite) {
      return FALSE;
    }

    // Keep the invitation claim and its access grants atomic. If Group rejects
    // a membership operation, the learner can retry the same claim instead of
    // being left with a claimed invitation and incomplete access.
    $transaction = $this->database->startTransaction();
    try {
      $claimed = $this->database->update('commerce_lms_entitlement_invitation')
        ->fields(['claimed_uid' => $uid])
        ->condition('id', $invite['id'])
        ->condition('token_hash', hash('sha256', $token))
        ->condition('expires', $this->time->getRequestTime(), '>')
        ->isNull('claimed_uid')
        ->execute();
      if ($claimed !== 1) {
        $transaction->rollBack();
        return FALSE;
      }
      $entitlement_ids = $this->database
        ->select('commerce_lms_entitlement', 'e')
        ->fields('e', ['eid'])
        ->condition('invitation_id', $invite['id'])
        ->execute()
        ->fetchCol();
      foreach ($entitlement_ids as $eid) {
        $entitlement = $this->load((int) $eid);
        $values = ['learner_uid' => $uid];
        $order = $entitlement
          ? $this->entityTypeManager->getStorage('commerce_order')->load($entitlement['order_id'])
          : NULL;
        $order_email = $order ? mb_strtolower((string) $order->getEmail()) : '';
        if ($entitlement && (int) $entitlement['purchaser_uid'] === 0 && $order_email === $invite['email']) {
          // A self-purchaser can create the account through the invitation
          // after checking out anonymously. In that case the verified invite
          // email is also sufficient to establish purchaser ownership.
          $values['purchaser_uid'] = $uid;
          if ((int) $order->getCustomerId() === 0) {
            $order->setCustomerId($uid);
            $order->save();
          }
        }
        $this->update((int) $eid, $values);
        $entitlement = $this->load((int) $eid);
        if ($entitlement['status'] === 'active') {
          $this->grant($entitlement);
        }
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    return TRUE;
  }

  /**
   * Returns whether this invitation is backed by an active entitlement.
   *
   * Invitation claim and PayPal activation are independent asynchronous
   * events. Callers use this check to avoid promising access while the linked
   * subscription is still pending.
   */
  public function invitationHasActiveAccess(string $invitation_id): bool {
    return (bool) $this->database
      ->select('commerce_lms_entitlement', 'e')
      ->condition('invitation_id', $invitation_id)
      ->condition('status', 'active')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Ensures each configured Class is an actual child of its configured Course.
   *
   * This permits a Course with many Classes while keeping the offer's class
   * selection explicit and administrator controlled.
   */
  private function validateTargets(object $offer): void {
    foreach ($offer->getCourseClassMap() as $target) {
      $course = $this->entityTypeManager->getStorage('group')->load((int) $target['course_id']); $class = $this->entityTypeManager->getStorage('group')->load((int) $target['class_id']);
      if (!$course || $course->bundle() !== 'lms_course' || !$class || $class->bundle() !== 'lms_class') { throw new \DomainException(sprintf('Offer %s has an invalid Course/Class target %d:%d.', $offer->id(), $target['course_id'], $target['class_id'])); }
      foreach ($course->getRelationships('lms_classes') as $relationship) { if ($relationship->getEntity()->id() === $class->id()) { continue 2; } }
      throw new \DomainException(sprintf('Class %d is not a child of Course %d for offer %s.', $class->id(), $course->id(), $offer->id()));
    }
  }
  /**
   * Grants this entitlement's bundle and records whether it created membership.
   *
   * A new ledger row does not claim an existing Group membership. A repeated
   * grant preserves the ownership decision already recorded in its ledger row.
   */
  private function grant(array $entitlement): void {
    if (empty($entitlement['learner_uid'])) { return; }
    $offer = $this->entityTypeManager->getStorage('commerce_lms_offer')->load($entitlement['offer_id']); if (!$offer) { throw new \RuntimeException('Missing offer ' . $entitlement['offer_id']); }
    $this->validateTargets($offer);
    $account = $this->entityTypeManager->getStorage('user')->load($entitlement['learner_uid']);
    if (!$account) {
      throw new \RuntimeException('Missing learner account ' . $entitlement['learner_uid']);
    }
    $this->membershipManager->grantTargets($entitlement, $account, $offer->getCourseClassMap(), 'base');
    if (!empty($entitlement['vip_active'])) {
      $this->membershipManager->grantTargets($entitlement, $account, [$this->vipTarget()], 'vip');
    }
  }

  /** Resolves the single configured VIP LMS hub target. */
  private function vipTarget(): array {
    $config = $this->configFactory->get('commerce_lms_entitlements.vip');
    $target = ['course_id' => (int) $config->get('hub_course_id'), 'class_id' => (int) $config->get('hub_class_id')];
    $stub = new class($target) {
      public function __construct(private array $target) {}
      public function id(): string { return 'vip'; }
      public function getCourseClassMap(): array { return [$this->target]; }
    };
    $this->validateTargets($stub);
    return $target;
  }
  /**
   * Removes access supported solely by this entitlement, never manual access.
   *
   * Membership rows are deactivated before checking other rows. That order is
   * what makes repeated revocation safe and lets another active entitlement
   * retain the shared Class membership.
   */
  private function revoke(array $entitlement): void {
    $this->revokeBenefit($entitlement);
  }

  /** Revokes all benefits, or only the selected benefit, idempotently. */
  private function revokeBenefit(array $entitlement, ?string $benefit = NULL): void {
    $this->membershipManager->revokeBenefit($entitlement, $benefit);
    $other_vip = !empty($entitlement['learner_uid']) && (bool) $this->database->select('commerce_lms_entitlement', 'e')
      ->condition('learner_uid', $entitlement['learner_uid'])
      ->condition('eid', $entitlement['eid'], '<>')
      ->condition('status', 'active')
      ->condition('vip_active', 1)
      ->countQuery()->execute()->fetchField();
    if (($benefit === NULL || $benefit === 'vip') && !empty($entitlement['learner_uid']) && !$other_vip) {
      $bookings = $this->database->select('commerce_lms_vip_booking', 'b')->fields('b')->condition('uid', $entitlement['learner_uid'])->execute()->fetchAll();
      foreach ($bookings as $booking) {
        $registrant = $this->entityTypeManager->getStorage('registrant')->load((int) $booking->registrant_id);
        $registrant?->delete();
      }
      $this->database->delete('commerce_lms_vip_booking')->condition('uid', $entitlement['learner_uid'])->execute();
    }
  }
}
