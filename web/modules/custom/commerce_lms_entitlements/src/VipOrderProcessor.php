<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\commerce_price\Price;

/** Adds the checkout-visible VIP recurring surcharge exactly once. */
final class VipOrderProcessor implements OrderProcessorInterface {

  public function __construct(
    private EntitlementManager $manager,
    private SubscriptionCampaignResolver $campaignResolver,
  ) {}

  /** {@inheritdoc} */
  public function process(OrderInterface $order): void {
    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getSourceId() === 'commerce_lms_entitlements:vip') {
        $order->removeAdjustment($adjustment);
      }
    }
    try {
      $offer = $this->manager->offerForOrder($order);
    }
    catch (\DomainException) {
      return;
    }
    if ($offer->getPurchaseType() !== 'recurring' || !$offer->isVipEnabled()) {
      return;
    }
    $context = NULL;
    try {
      $context = $this->campaignResolver->resolve($order, $offer);
    }
    catch (\DomainException) {
      // Checkout validation reports unsupported or conflicting coupons. Keep
      // order refresh non-fatal so the customer can remove the coupon.
    }
    $auto_campaign = (string) ($order->getData('commerce_lms_campaign_auto_vip') ?? '');
    if ($context && $context['campaign']->forcesVip()) {
      $campaign_id = $context['campaign']->id();
      if ($auto_campaign !== $campaign_id) {
        if ($auto_campaign === '') {
          $order->setData('commerce_lms_campaign_previous_vip', (bool) $order->getData('commerce_lms_vip_selected'));
        }
        $order->setData('commerce_lms_campaign_auto_vip', $campaign_id);
      }
      $order->setData('commerce_lms_vip_selected', TRUE);
    }
    elseif ($auto_campaign !== '') {
      $order->setData('commerce_lms_vip_selected', (bool) $order->getData('commerce_lms_campaign_previous_vip'));
      $order->setData('commerce_lms_campaign_previous_vip', NULL);
      $order->setData('commerce_lms_campaign_auto_vip', NULL);
    }
    if (!$order->getData('commerce_lms_vip_selected')) {
      return;
    }
    $order->addAdjustment(new Adjustment([
      'type' => 'fee',
      'label' => 'VIP upgrade',
      'amount' => new Price($offer->getVipSurchargeNumber(), $offer->getVipSurchargeCurrency()),
      'source_id' => 'commerce_lms_entitlements:vip',
      'included' => FALSE,
      'locked' => TRUE,
    ]));
  }

}
