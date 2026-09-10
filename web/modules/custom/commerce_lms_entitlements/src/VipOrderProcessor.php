<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\commerce_price\Price;

/** Adds the checkout-visible VIP recurring surcharge exactly once. */
final class VipOrderProcessor implements OrderProcessorInterface {

  public function __construct(private EntitlementManager $manager) {}

  /** {@inheritdoc} */
  public function process(OrderInterface $order): void {
    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getSourceId() === 'commerce_lms_entitlements:vip') {
        $order->removeAdjustment($adjustment);
      }
    }
    if (!$order->getData('commerce_lms_vip_selected')) {
      return;
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
