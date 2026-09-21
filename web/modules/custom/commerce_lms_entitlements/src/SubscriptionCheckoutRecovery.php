<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\PaymentOrderUpdaterInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Symfony\Component\HttpFoundation\Request;

/** Finalizes an approved subscription independently of the browser return. */
final class SubscriptionCheckoutRecovery {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private EntitlementManager $manager,
    private SubscriptionCheckoutSnapshot $checkoutSnapshot,
    private PayPalSubscriptionOperations $paypalOperations,
    private LockBackendInterface $lock,
    private PaymentOrderUpdaterInterface $paymentOrderUpdater,
  ) {}

  /**
   * Finalizes one approved PayPal subscription idempotently.
   *
   * @param bool $require_payment_evidence
   *   Require PayPal's last-payment amount. Webhook recovery uses this extra
   *   guard; the synchronous approval callback retains the gateway's normal
   *   ACTIVE-subscription behavior.
   * @param string $paypal_order_id
   *   The PayPal approval order ID, when finalization is browser-initiated.
   */
  public function finalize(
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
    string $subscription_id,
    bool $require_payment_evidence = FALSE,
    string $paypal_order_id = '',
  ): array {
    if ($subscription_id === '') {
      throw new \DomainException('PayPal did not provide a subscription ID.');
    }
    $lock_name = 'commerce_lms_entitlements.subscription_recovery.' . $order->id();
    if (!$this->lock->acquire($lock_name, 30.0)) {
      throw new \RuntimeException('Subscription finalization is already in progress.');
    }

    try {
      $order_storage = $this->entityTypeManager->getStorage('commerce_order');
      $order = $order_storage->loadUnchanged($order->id());
      if (!$order instanceof OrderInterface) {
        throw new \DomainException('The subscription order no longer exists.');
      }
      $entitlement = $this->manager->loadByOrder((int) $order->id());
      if (!$entitlement || $entitlement['purchase_type'] !== 'recurring') {
        throw new \DomainException('The order has no recurring LMS entitlement.');
      }
      if (!empty($entitlement['paypal_subscription_id'])
        && $entitlement['paypal_subscription_id'] !== $subscription_id) {
        throw new \DomainException('The order is linked to a different PayPal subscription.');
      }

      $remote = $this->paypalOperations->fetchSubscription($gateway, $subscription_id);
      $this->validateRemote($entitlement, $remote, $subscription_id);
      $snapshot = $this->decodeSnapshot($entitlement);
      $this->validateSnapshotIdentity($order, $gateway, $snapshot);
      $expected_amount = Price::fromArray($snapshot['total']);
      if ($require_payment_evidence) {
        $this->validateLastPayment($remote, $expected_amount);
      }

      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $remote_payments = $payment_storage->loadByProperties(['remote_id' => $subscription_id]);
      if (count($remote_payments) > 1) {
        throw new \DomainException('More than one Commerce payment uses this PayPal subscription.');
      }
      $payment = $remote_payments ? reset($remote_payments) : NULL;
      if ($payment && (int) $payment->getOrderId() !== (int) $order->id()) {
        throw new \DomainException('The PayPal subscription is attached to another Commerce order.');
      }
      if ($payment && (string) $payment->getPaymentGatewayId() !== (string) $gateway->id()) {
        throw new \DomainException('The PayPal subscription payment uses another Commerce gateway.');
      }
      foreach ($payment_storage->loadByProperties(['order_id' => (int) $order->id()]) as $order_payment) {
        if (!$payment || (int) $order_payment->id() !== (int) $payment->id()) {
          throw new \DomainException('The subscription order already has another Commerce payment.');
        }
      }

      if ($order->getState()->value === 'draft') {
        $expected_amount = $this->checkoutSnapshot->restore($order, $snapshot);
        if (!$payment) {
          $gateway->getPlugin()->onReturn($order, Request::create('/', 'GET', [
            'orderID' => $paypal_order_id,
            'subscriptionID' => $subscription_id,
          ]));
          $remote_payments = $payment_storage->loadByProperties(['remote_id' => $subscription_id]);
          if (count($remote_payments) !== 1) {
            throw new \RuntimeException('Subscription approval did not create exactly one Commerce payment.');
          }
          $payment = reset($remote_payments);
        }
      }
      elseif ($order->getState()->value !== 'completed') {
        throw new \DomainException('The subscription order is neither draft nor completed.');
      }
      if (!$payment) {
        throw new \DomainException('The completed subscription order has no Commerce payment.');
      }
      if ($payment->getState()->value !== 'completed') {
        throw new \DomainException('The subscription Commerce payment is not completed.');
      }
      if (!$payment->getAmount()->equals($expected_amount)) {
        throw new \DomainException(sprintf(
          'Commerce payment amount %s does not match approved checkout amount %s.',
          (string) $payment->getAmount(),
          (string) $expected_amount,
        ));
      }

      $order->setData('paypal_subscription_id', $subscription_id);
      if ($order->getState()->value === 'draft') {
        $order->set('checkout_step', 'complete');
        $order->unlock();
        $order->getState()->applyTransitionById('place');
      }
      $order->save();
      $this->paymentOrderUpdater->updateOrder($order, TRUE);

      $this->manager->linkPayPalSubscriptionFromOrder($order);
      $entitlement = $this->manager->loadByOrder((int) $order->id());
      if (!$entitlement || $entitlement['paypal_subscription_id'] !== $subscription_id) {
        throw new \RuntimeException('The completed order did not link its PayPal subscription.');
      }
      $this->manager->applyRemoteSubscription($entitlement, $remote);
      $entitlement = $this->manager->load((int) $entitlement['eid']);
      if (!$entitlement) {
        throw new \RuntimeException('The recovered entitlement disappeared during finalization.');
      }
      return $entitlement;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /** Ensures PayPal returned the exact object authorized by the sealed order. */
  private function validateRemote(array $entitlement, array $remote, string $subscription_id): void {
    if (($remote['id'] ?? '') !== $subscription_id) {
      throw new \DomainException('PayPal returned a different subscription.');
    }
    if (($remote['status'] ?? '') !== 'ACTIVE') {
      throw new \DomainException('The PayPal subscription is not active.');
    }
    if (($remote['plan_id'] ?? '') !== ($entitlement['paypal_plan_id'] ?? '')) {
      throw new \DomainException('The PayPal subscription plan does not match the sealed checkout.');
    }
    if (($remote['custom_id'] ?? '') !== ($entitlement['checkout_token'] ?? '')) {
      throw new \DomainException('The PayPal subscription is not correlated to the sealed checkout.');
    }
  }

  /** Prevents a valid subscription from being applied to another order. */
  private function validateSnapshotIdentity(OrderInterface $order, PaymentGatewayInterface $gateway, array $snapshot): void {
    $order_gateway_id = (string) ($order->get('payment_gateway')->target_id ?? '');
    if ((int) ($snapshot['order_id'] ?? 0) !== (int) $order->id()
      || (string) ($snapshot['gateway_id'] ?? '') !== (string) $gateway->id()
      || $order_gateway_id !== (string) $gateway->id()) {
      throw new \DomainException('The subscription checkout identity does not match the sealed order.');
    }
  }

  /** Requires PayPal to show a payment equal to the immutable initial total. */
  private function validateLastPayment(array $remote, Price $expected): void {
    $amount = $remote['billing_info']['last_payment']['amount'] ?? NULL;
    if (!is_array($amount)
      || !isset($amount['value'], $amount['currency_code'])
      || !is_scalar($amount['value'])
      || !is_string($amount['currency_code'])) {
      throw new \DomainException('The active PayPal subscription does not yet show a completed payment.');
    }
    $paid = new Price((string) $amount['value'], $amount['currency_code']);
    if (!$paid->equals($expected)) {
      throw new \DomainException(sprintf(
        'PayPal payment amount %s does not match approved checkout amount %s.',
        (string) $paid,
        (string) $expected,
      ));
    }
  }

  /** Decodes the snapshot only after its correlation token has been verified. */
  private function decodeSnapshot(array $entitlement): array {
    $encoded = (string) ($entitlement['checkout_snapshot'] ?? '');
    if ($encoded === '') {
      throw new \DomainException('The pending entitlement has no checkout snapshot.');
    }
    $snapshot = json_decode($encoded, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($snapshot)) {
      throw new \DomainException('The pending entitlement checkout snapshot is invalid.');
    }
    return $snapshot;
  }

}
