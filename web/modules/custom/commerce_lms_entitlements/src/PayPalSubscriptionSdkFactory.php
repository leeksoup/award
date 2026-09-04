<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\commerce_order\AdjustmentTransformerInterface;
use Drupal\commerce_paypal_subscriptions\CheckoutSdkFactory as UpstreamCheckoutSdkFactory;
use Drupal\commerce_price\RounderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Adapts the contributed subscription SDK factory to Commerce PayPal 1.12.
 *
 * commerce_paypal_subscriptions 1.0.0 retains the pre-1.12 service arguments
 * for its CheckoutSdkFactory. Commerce PayPal 1.12 changed SdkFactoryBase to
 * use a key-value token cache and logger, moving the constructor arguments.
 * The upstream factory inherits that constructor, so its original service
 * definition passes a HandlerStack where an adjustment transformer is needed.
 *
 * This replacement changes dependency injection only. The inherited `get()`
 * method still creates the contributed CheckoutSdk, including its subscription
 * endpoints. It exists in this custom module so contrib remains unmodified and
 * can be removed when the upstream service definition is corrected.
 */
final class PayPalSubscriptionSdkFactory extends UpstreamCheckoutSdkFactory {

  public function __construct(
    ClientFactory $client_factory,
    AdjustmentTransformerInterface $adjustment_transformer,
    EventDispatcherInterface $event_dispatcher,
    ModuleHandlerInterface $module_handler,
    TimeInterface $time,
    RounderInterface $rounder,
    KeyValueExpirableFactoryInterface $key_value_expirable_factory,
    LoggerInterface $logger,
  ) {
    parent::__construct(
      $client_factory,
      $adjustment_transformer,
      $event_dispatcher,
      $module_handler,
      $time,
      $rounder,
      $key_value_expirable_factory,
      $logger,
    );
  }

}
