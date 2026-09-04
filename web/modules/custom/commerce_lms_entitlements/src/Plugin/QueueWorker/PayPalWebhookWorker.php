<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\QueueWorker;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Fetches current PayPal subscription detail after verified event receipt.
 * @QueueWorker(id = "commerce_lms_entitlements_webhook", title = @Translation("Commerce LMS PayPal webhook"), cron = {"time" = 30})
 */
final class PayPalWebhookWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private EntitlementManager $manager, private object $sdkFactory) { parent::__construct($configuration, $plugin_id, $plugin_definition); }
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static { return new static($configuration, $plugin_id, $plugin_definition, $container->get('commerce_lms_entitlements.manager'), $container->get('commerce_paypal_subscriptions.checkout_sdk_factory')); }
  public function processItem($data): void {
    $event = $this->manager->event($data['event_id']); if (!$event || $event['status'] === 'processed') { return; }
    try {
      $entitlement = $this->manager->loadByPayPalSubscription((string) $event['paypal_subscription_id']);
      if (!$entitlement) { $this->manager->markEvent($event['event_id'], 'ignored'); return; }
      $order = \Drupal::entityTypeManager()->getStorage('commerce_order')->load($entitlement['order_id']); $gateway_id = $order->get('payment_gateway')->target_id ?? NULL;
      $gateway = $gateway_id ? \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway')->load($gateway_id) : NULL;
      if (!$gateway) { throw new \RuntimeException('Missing payment gateway for entitlement ' . $entitlement['eid']); }
      $response = $this->sdkFactory->get($gateway->getPluginConfiguration())->getSubscription($entitlement['paypal_subscription_id']);
      $this->manager->applyRemoteSubscription($entitlement, json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR));
      $this->manager->markEvent($event['event_id'], 'processed');
    }
    catch (\Throwable $e) { $this->manager->markEvent($event['event_id'], 'failed'); throw $e; }
  }
}
