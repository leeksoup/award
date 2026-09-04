<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\group\Entity\GroupMembership;
use Psr\Log\LoggerInterface;

/**
 * Maintains the local learner-entitlement audit trail and safe Class access.
 */
final class EntitlementManager {
  public function __construct(private Connection $database, private EntityTypeManagerInterface $entityTypeManager, private QueueFactory $queue, private TimeInterface $time, private LoggerInterface $logger) {}

  /** Returns the sole offer for a strict, single-variation checkout. */
  public function offerForOrder(object $order): object {
    $items = $order->getItems();
    if (count($items) !== 1 || (string) $items[0]->getQuantity() !== '1') {
      throw new \DomainException('An LMS offer checkout must contain exactly one item at quantity one.');
    }
    $offers = $this->entityTypeManager->getStorage('commerce_lms_offer')->loadByProperties(['variation_id' => (int) $items[0]->getPurchasedEntityId()]);
    if (count($offers) !== 1) { throw new \DomainException('The purchased variation has no unique LMS offer.'); }
    $offer = reset($offers);
    $this->validateTargets($offer);
    return $offer;
  }

  /** Creates one pending entitlement from an order's designated learner data. */
  public function ensureEntitlement(object $order, object $offer): array {
    if ($existing = $this->loadByOrder((int) $order->id())) { return $existing; }
    $learner = $order->getData('commerce_lms_learner') ?: [];
    if (empty($learner['uid']) && empty($learner['invitation_id'])) { throw new \DomainException('A learner must be selected before payment approval.'); }
    $now = $this->time->getRequestTime();
    $this->database->insert('commerce_lms_entitlement')->fields([
      'offer_id' => $offer->id(), 'purchase_type' => $offer->getPurchaseType(), 'purchaser_uid' => (int) $order->getCustomerId(),
      'learner_uid' => !empty($learner['uid']) ? (int) $learner['uid'] : NULL, 'invitation_id' => $learner['invitation_id'] ?? NULL,
      'order_id' => (int) $order->id(), 'status' => 'pending', 'created' => $now, 'changed' => $now,
    ])->execute();
    return $this->loadByOrder((int) $order->id());
  }

  public function load(int $eid): ?array { $row = $this->database->select('commerce_lms_entitlement', 'e')->fields('e')->condition('eid', $eid)->execute()->fetchAssoc(); return $row ?: NULL; }
  public function loadByOrder(int $order_id): ?array { $row = $this->database->select('commerce_lms_entitlement', 'e')->fields('e')->condition('order_id', $order_id)->execute()->fetchAssoc(); return $row ?: NULL; }
  public function loadByPayPalSubscription(string $id): ?array { $row = $this->database->select('commerce_lms_entitlement', 'e')->fields('e')->condition('paypal_subscription_id', $id)->execute()->fetchAssoc(); return $row ?: NULL; }
  public function update(int $eid, array $values): void { $values['changed'] = $this->time->getRequestTime(); $this->database->update('commerce_lms_entitlement')->fields($values)->condition('eid', $eid)->execute(); }

  /** Links the subscription ID which contributed checkout persists on the order. */
  public function linkPayPalSubscriptionFromOrder(object $order): void {
    $id = $order->getData('paypal_subscription_id');
    if (!is_string($id) || $id === '' || !($entitlement = $this->loadByOrder((int) $order->id()))) { return; }
    $this->update((int) $entitlement['eid'], ['paypal_subscription_id' => $id]);
    // A webhook can legitimately arrive between buyer approval and this order
    // update. Requeue those persisted events now that they can be associated.
    foreach ($this->database->select('commerce_lms_entitlement_event', 'e')->fields('e', ['event_id'])->condition('paypal_subscription_id', $id)->condition('status', ['queued', 'failed'], 'IN')->execute()->fetchCol() as $event_id) {
      $this->queue->get('commerce_lms_entitlements_webhook')->createItem(['event_id' => $event_id]);
    }
  }

  /** Creates lifetime access only after the normal Commerce payment completes. */
  public function syncCompletedPayment(object $payment): void {
    if (!$payment->getOrder() || $payment->getState()->value !== 'completed') { return; }
    $order = $payment->getOrder();
    try { $offer = $this->offerForOrder($order); }
    catch (\DomainException) { return; }
    if ($offer->getPurchaseType() !== 'lifetime') { return; }
    if ((string) ($payment->getPaymentGateway()->id() ?? '') !== $offer->getPaymentGatewayId()) {
      $this->logger->warning('Lifetime order @order completed through unexpected gateway @gateway.', ['@order' => $order->id(), '@gateway' => $payment->getPaymentGateway()->id() ?? 'none']);
      return;
    }
    $entitlement = $this->ensureEntitlement($order, $offer);
    if ($entitlement['status'] !== 'active') {
      $this->update((int) $entitlement['eid'], ['payment_id' => (int) $payment->id(), 'initial_capture_id' => $payment->getRemoteId(), 'status' => 'active', 'activated' => $this->time->getRequestTime()]);
      $this->grant($this->load((int) $entitlement['eid']));
    }
  }

  /** Persists a verified event exactly once and schedules the remote-state fetch. */
  public function queueEvent(array $event): bool {
    $event_id = (string) ($event['id'] ?? '');
    if ($event_id === '') { throw new \InvalidArgumentException('PayPal event is missing its ID.'); }
    $resource = $event['resource'] ?? []; $subscription_id = (string) ($resource['id'] ?? ''); $now = $this->time->getRequestTime();
    try { $this->database->insert('commerce_lms_entitlement_event')->fields(['event_id' => $event_id, 'paypal_subscription_id' => $subscription_id ?: NULL, 'event_type' => (string) ($event['event_type'] ?? ''), 'payload' => json_encode($event, JSON_THROW_ON_ERROR), 'created' => $now, 'changed' => $now])->execute(); }
    catch (\Exception) { return FALSE; }
    $this->queue->get('commerce_lms_entitlements_webhook')->createItem(['event_id' => $event_id]);
    return TRUE;
  }
  public function event(string $event_id): ?array { $row = $this->database->select('commerce_lms_entitlement_event', 'e')->fields('e')->condition('event_id', $event_id)->execute()->fetchAssoc(); return $row ?: NULL; }
  public function markEvent(string $event_id, string $status): void { $this->database->update('commerce_lms_entitlement_event')->fields(['status' => $status, 'changed' => $this->time->getRequestTime()])->condition('event_id', $event_id)->execute(); }

  /** Applies current PayPal subscription detail, never just the webhook payload. */
  public function applyRemoteSubscription(array $entitlement, array $remote): void {
    $status = strtoupper((string) ($remote['status'] ?? ''));
    $local = ['ACTIVE' => 'active', 'SUSPENDED' => 'suspended', 'CANCELLED' => 'cancelled', 'EXPIRED' => 'expired'][$status] ?? 'pending';
    $through = !empty($remote['billing_info']['next_billing_time']) ? strtotime($remote['billing_info']['next_billing_time']) ?: NULL : NULL;
    $values = ['status' => $local, 'access_through' => $through];
    if ($local === 'active' && empty($entitlement['activated'])) { $values['activated'] = $this->time->getRequestTime(); }
    $capture = $remote['billing_info']['last_payment']['transaction_id'] ?? NULL;
    if ($capture && empty($entitlement['initial_capture_id'])) { $values['initial_capture_id'] = $capture; }
    $this->update((int) $entitlement['eid'], $values); $current = $this->load((int) $entitlement['eid']);
    if ($local === 'active') { $this->grant($current); }
    elseif (in_array($local, ['suspended', 'expired'], TRUE) || ($local === 'cancelled' && (!$through || $through <= $this->time->getRequestTime()))) { $this->revoke($current); }
  }

  public function reconcile(): void {
    $ids = $this->database->select('commerce_lms_entitlement', 'e')->fields('e', ['eid'])->condition('status', 'cancelled')->condition('access_through', $this->time->getRequestTime(), '<=')->execute()->fetchCol();
    foreach ($ids as $id) { if ($entitlement = $this->load((int) $id)) { $this->revoke($entitlement); } }
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
  /** Loads an unclaimed, unexpired invitation without exposing its token hash. */
  public function invitation(string $token): ?array {
    $invite = $this->database->select('commerce_lms_entitlement_invitation', 'i')->fields('i')->condition('token_hash', hash('sha256', $token))->condition('expires', $this->time->getRequestTime(), '>')->isNull('claimed_uid')->execute()->fetchAssoc();
    return $invite ?: NULL;
  }
  public function claimInvitation(string $token, int $uid, string $email): bool {
    $invite = $this->invitation($token);
    if ($invite && mb_strtolower($email) !== $invite['email']) {
      return FALSE;
    }
    if (!$invite) { return FALSE; }
    $this->database->update('commerce_lms_entitlement_invitation')->fields(['claimed_uid' => $uid])->condition('id', $invite['id'])->execute();
    foreach ($this->database->select('commerce_lms_entitlement', 'e')->fields('e', ['eid'])->condition('invitation_id', $invite['id'])->execute()->fetchCol() as $eid) { $this->update((int) $eid, ['learner_uid' => $uid]); $entitlement = $this->load((int) $eid); if ($entitlement['status'] === 'active') { $this->grant($entitlement); } }
    return TRUE;
  }

  private function validateTargets(object $offer): void {
    foreach ($offer->getCourseClassMap() as $target) {
      $course = $this->entityTypeManager->getStorage('group')->load((int) $target['course_id']); $class = $this->entityTypeManager->getStorage('group')->load((int) $target['class_id']);
      if (!$course || $course->bundle() !== 'lms_course' || !$class || $class->bundle() !== 'lms_class') { throw new \DomainException(sprintf('Offer %s has an invalid Course/Class target %d:%d.', $offer->id(), $target['course_id'], $target['class_id'])); }
      foreach ($course->getRelationships('lms_classes') as $relationship) { if ($relationship->getEntity()->id() === $class->id()) { continue 2; } }
      throw new \DomainException(sprintf('Class %d is not a child of Course %d for offer %s.', $class->id(), $course->id(), $offer->id()));
    }
  }
  private function grant(array $entitlement): void {
    if (empty($entitlement['learner_uid'])) { return; }
    $offer = $this->entityTypeManager->getStorage('commerce_lms_offer')->load($entitlement['offer_id']); if (!$offer) { throw new \RuntimeException('Missing offer ' . $entitlement['offer_id']); }
    $this->validateTargets($offer); $account = $this->entityTypeManager->getStorage('user')->load($entitlement['learner_uid']);
    foreach ($offer->getCourseClassMap() as $target) {
      $class = $this->entityTypeManager->getStorage('group')->load((int) $target['class_id']); $membership = GroupMembership::loadByGroupAndUser($class, $account);
      $this->database->merge('commerce_lms_entitlement_membership')->key(['eid' => $entitlement['eid'], 'class_id' => $class->id()])->fields(['uid' => $account->id(), 'membership_created' => $membership ? 0 : 1, 'active' => 1, 'created' => $this->time->getRequestTime()])->execute();
      if (!$membership) { $class->addMember($account)->save(); }
    }
  }
  private function revoke(array $entitlement): void {
    foreach ($this->database->select('commerce_lms_entitlement_membership', 'm')->fields('m')->condition('eid', $entitlement['eid'])->condition('active', 1)->execute()->fetchAll() as $membership) {
      $this->database->update('commerce_lms_entitlement_membership')->fields(['active' => 0])->condition('id', $membership->id)->execute();
      $other = $this->database->select('commerce_lms_entitlement_membership', 'm')->condition('class_id', $membership->class_id)->condition('uid', $membership->uid)->condition('active', 1)->countQuery()->execute()->fetchField();
      if (!$membership->membership_created || $other) { continue; }
      $class = $this->entityTypeManager->getStorage('group')->load($membership->class_id); $account = $this->entityTypeManager->getStorage('user')->load($membership->uid);
      if ($class && $account && ($group_membership = GroupMembership::loadByGroupAndUser($class, $account))) { $group_membership->getGroupRelationship()->delete(); }
    }
  }
}
