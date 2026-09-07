<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\EventSubscriber;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_payment\Event\FilterPaymentGatewaysEvent;
use Drupal\commerce_payment\Event\PaymentEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Restricts a valid LMS offer order to its configured payment gateway.
 *
 * Commerce can otherwise present any gateway whose general conditions match
 * the order. An LMS offer is more specific: recurring variations must use the
 * PayPal Subscriptions gateway that creates the configured plan, while a
 * lifetime variation must use its configured one-time gateway.
 */
final class PaymentGatewaySubscriber implements EventSubscriberInterface {

  public function __construct(private EntitlementManager $manager) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PaymentEvents::FILTER_PAYMENT_GATEWAYS => 'filterPaymentGateways',
    ];
  }

  /**
   * Keeps only the gateway selected by the variation's LMS offer.
   */
  public function filterPaymentGateways(FilterPaymentGatewaysEvent $event): void {
    try {
      $offer = $this->manager->offerForOrder($event->getOrder());
    }
    catch (\DomainException) {
      // Non-LMS and structurally invalid orders are handled elsewhere. Do not
      // change their gateway list merely because no valid offer was resolved.
      return;
    }

    $payment_gateways = $event->getPaymentGateways();
    $gateway_id = $offer->getPurchaseType() === 'recurring'
      ? $offer->getActivePayPalGatewayId()
      : $offer->getPaymentGatewayId();
    $event->setPaymentGateways(isset($payment_gateways[$gateway_id])
      ? [$gateway_id => $payment_gateways[$gateway_id]]
      : []);
  }

}
