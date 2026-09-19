<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\EventSubscriber;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\SubscriptionCampaignResolver;
use Drupal\commerce_lms_entitlements\SubscriptionCheckoutSnapshot;
use Drupal\commerce_paypal_subscriptions\Event\PaypalSubscriptionCreateEvent;
use Drupal\commerce_paypal_subscriptions\Event\PaypalSubscriptionEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Selects the administrator-configured PayPal plan for a recurring variation. */
final class PayPalPlanSubscriber implements EventSubscriberInterface {
  public function __construct(
    private EntitlementManager $manager,
    private SubscriptionCampaignResolver $campaignResolver,
    private SubscriptionCheckoutSnapshot $checkoutSnapshot,
  ) {}
  public static function getSubscribedEvents(): array { return [PaypalSubscriptionEvents::SUBSCRIPTION_CREATE->value => 'selectPlan']; }
  public function selectPlan(PaypalSubscriptionCreateEvent $event): void {
    $order = $event->getOrder(); $offer = $this->manager->offerForOrder($order);
    if ($offer->getPurchaseType() !== 'recurring') { throw new \DomainException('A lifetime offer cannot use the PayPal subscriptions gateway.'); }
    $gateway_id = (string) ($order->get('payment_gateway')->target_id ?? '');
    if ($gateway_id !== $offer->getActivePayPalGatewayId()) { throw new \DomainException('The selected payment gateway is not permitted for this offer environment.'); }
    $selection = $this->campaignResolver->selectPlan($order, $offer);
    $entitlement = $this->manager->ensureEntitlement($order, $offer, $selection);
    $this->manager->sealRecurringCheckout(
      $entitlement,
      $this->checkoutSnapshot->capture($order),
    );
    $event->setPlanId($selection->planId);
  }
}
