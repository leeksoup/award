<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\QueueWorker;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\PayPalSubscriptionOperations;
use Drupal\commerce_lms_entitlements\SubscriptionCheckoutRecovery;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Fetches current PayPal subscription detail after verified event receipt.
 * @QueueWorker(id = "commerce_lms_entitlements_webhook", title = @Translation("Commerce LMS PayPal webhook"), cron = {"time" = 30})
 */
final class PayPalWebhookWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private EntitlementManager $manager,
    private EntityTypeManagerInterface $entityTypeManager,
    private PayPalSubscriptionOperations $paypalOperations,
    private SubscriptionCheckoutRecovery $checkoutRecovery,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('commerce_lms_entitlements.manager'),
      $container->get('entity_type.manager'),
      $container->get('commerce_lms_entitlements.paypal_operations'),
      $container->get('commerce_lms_entitlements.subscription_checkout_recovery'),
    );
  }
  public function processItem($data): void {
    $event = $this->manager->event($data['event_id']); if (!$event || $event['status'] === 'processed') { return; }
    try {
      if (!$this->manager->isSubscriptionEvent($event)) {
        $this->manager->markEvent($event['event_id'], 'processed');
        return;
      }
      // Re-extract the subscription ID from the saved payload so events
      // accepted by an older module version are repaired as they run.
      $subscription_id = $this->manager->resolveEventSubscriptionId($event);
      if ($subscription_id === '') {
        throw new \UnexpectedValueException(sprintf('PayPal event %s does not identify a subscription.', $event['event_id']));
      }
      $entitlement = $this->manager->loadByPayPalSubscription($subscription_id);
      $gateway = NULL;
      $remote = NULL;
      if (!$entitlement) {
        // PayPal can notify us before the browser returns. New checkouts carry
        // an unguessable custom_id which lets the verified webhook finalize
        // the exact sealed cart without trusting its current mutable total.
        $gateway_id = (string) ($event['gateway_id'] ?? '');
        $gateway = $gateway_id !== ''
          ? $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($gateway_id)
          : NULL;
        if (!$gateway) {
          return;
        }
        $remote = $this->paypalOperations->fetchSubscription($gateway, $subscription_id);
        $checkout_token = (string) ($remote['custom_id'] ?? '');
        $entitlement = $checkout_token !== ''
          ? $this->manager->loadByCheckoutToken($checkout_token)
          : NULL;
        if (!$entitlement || ($remote['status'] ?? '') !== 'ACTIVE'
          || empty($remote['billing_info']['last_payment']['amount'])) {
          return;
        }
        $recovery_order = $this->entityTypeManager->getStorage('commerce_order')->load($entitlement['order_id']);
        if (!$recovery_order instanceof OrderInterface) {
          throw new \RuntimeException('Missing Commerce order for recoverable entitlement ' . $entitlement['eid']);
        }
        $this->checkoutRecovery->finalize(
          $recovery_order,
          $gateway,
          $subscription_id,
          TRUE,
        );
        $entitlement = $this->manager->loadByPayPalSubscription($subscription_id);
        if (!$entitlement) {
          throw new \RuntimeException('Recovered checkout did not link its PayPal subscription.');
        }
      }
      $order = $this->entityTypeManager->getStorage('commerce_order')->load($entitlement['order_id']);
      if (!$order instanceof OrderInterface) {
        throw new \RuntimeException('Missing Commerce order for entitlement ' . $entitlement['eid']);
      }
      $gateway_id = $order->get('payment_gateway')->target_id ?? NULL;
      $gateway ??= $gateway_id ? $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($gateway_id) : NULL;
      if (!$gateway) { throw new \RuntimeException('Missing payment gateway for entitlement ' . $entitlement['eid']); }
      $remote ??= $this->paypalOperations->fetchSubscription($gateway, $entitlement['paypal_subscription_id']);
      $this->manager->applyRemoteSubscription($entitlement, $remote);
      $this->manager->markEvent($event['event_id'], 'processed');
    }
    catch (\Throwable $e) { $this->manager->markEvent($event['event_id'], 'failed'); throw $e; }
  }
}
