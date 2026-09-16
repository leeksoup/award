<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\commerce_lms_entitlements\Entity\LmsOffer;
use Drupal\commerce_lms_entitlements\Entity\LmsSubscriptionCampaign;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Calculator;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/** Resolves a Commerce coupon to one curated subscription campaign. */
final class SubscriptionCampaignResolver {

  public const OFFER_PLUGIN_ID = 'lms_subscription_campaign';

  public function __construct(private EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Resolves the campaign applied to a recurring LMS offer.
   *
   * @return array{campaign: \Drupal\commerce_lms_entitlements\Entity\LmsSubscriptionCampaign, mapping: array<string, mixed>, promotion_uuid: string, coupon_uuid: string}|null
   *   The resolved campaign context, or NULL when no coupon is present.
   *
   * @throws \DomainException
   *   When coupons are stacked, unsupported, disabled, or ineligible.
   */
  public function resolve(OrderInterface $order, LmsOffer $lms_offer): ?array {
    $coupons = $order->hasField('coupons')
      ? $order->get('coupons')->referencedEntities()
      : [];
    if (!$coupons) {
      return NULL;
    }
    if (count($coupons) !== 1) {
      throw new \DomainException('Recurring LMS subscriptions accept at most one coupon.');
    }

    $coupon = reset($coupons);
    $promotion = method_exists($coupon, 'getPromotion') ? $coupon->getPromotion() : NULL;
    $promotion_offer = $promotion && method_exists($promotion, 'getOffer')
      ? $promotion->getOffer()
      : NULL;
    if (!$promotion_offer || $promotion_offer->getPluginId() !== self::OFFER_PLUGIN_ID) {
      throw new \DomainException('This coupon is not supported for recurring LMS subscriptions.');
    }

    $campaign_id = trim((string) ($promotion_offer->getConfiguration()['campaign_id'] ?? ''));
    $campaign = $campaign_id !== ''
      ? $this->entityTypeManager->getStorage(LmsSubscriptionCampaign::ENTITY_TYPE_ID)->load($campaign_id)
      : NULL;
    if (!$campaign instanceof LmsSubscriptionCampaign || !$campaign->status()) {
      throw new \DomainException('The subscription campaign for this coupon is missing or disabled.');
    }
    $mapping = $campaign->getOfferMapping($lms_offer->id());
    if (!$mapping) {
      throw new \DomainException(sprintf('Subscription campaign %s does not permit offer %s.', $campaign->id(), $lms_offer->id()));
    }

    return [
      'campaign' => $campaign,
      'mapping' => $mapping,
      'promotion_uuid' => (string) $promotion->uuid(),
      'coupon_uuid' => (string) $coupon->uuid(),
    ];
  }

  /** Selects and validates the PayPal plan represented by the checkout total. */
  public function selectPlan(OrderInterface $order, LmsOffer $lms_offer): SubscriptionPlanSelection {
    $vip = $lms_offer->isVipEnabled() && (bool) $order->getData('commerce_lms_vip_selected');
    $context = $this->resolve($order, $lms_offer);
    if (!$context) {
      $plan_id = $vip
        ? $lms_offer->getActivePayPalVipPlanId()
        : $lms_offer->getActivePayPalPlanId();
      if ($plan_id === '') {
        throw new \DomainException('The active PayPal environment has no subscription plan mapping.');
      }
      return new SubscriptionPlanSelection(
        offerId: $lms_offer->id(),
        planId: $plan_id,
        vip: $vip,
      );
    }

    $campaign = $context['campaign'];
    if ($campaign->forcesVip()) {
      $vip = TRUE;
    }
    $plan_id = $campaign->getPayPalPlanId($lms_offer->id(), $lms_offer->getPayPalEnvironment(), $vip);
    $intro_price = $campaign->getIntroPrice($lms_offer->id(), $vip);
    if ($plan_id === '' || !$intro_price) {
      throw new \DomainException(sprintf('Subscription campaign %s has no active plan and introductory price for the selected tier.', $campaign->id()));
    }
    $order_total = $order->getTotalPrice();
    if (!$order_total || $order_total->getCurrencyCode() !== $intro_price->getCurrencyCode() || Calculator::compare($order_total->getNumber(), $intro_price->getNumber()) !== 0) {
      throw new \DomainException(sprintf(
        'The checkout total does not match subscription campaign %s. Expected %s %s.',
        $campaign->id(),
        $intro_price->getNumber(),
        $intro_price->getCurrencyCode(),
      ));
    }

    return new SubscriptionPlanSelection(
      offerId: $lms_offer->id(),
      planId: $plan_id,
      vip: $vip,
      campaignId: $campaign->id(),
      promotionUuid: $context['promotion_uuid'],
      couponUuid: $context['coupon_uuid'],
      introPrice: $intro_price,
      introCycles: $campaign->getIntroCycles($lms_offer->id()),
    );
  }

}
