<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Unit;

use Drupal\commerce_lms_entitlements\Entity\LmsOffer;
use Drupal\Tests\UnitTestCase;

/** Tests environment-specific base and VIP PayPal plan selection. */
final class LmsOfferVipTest extends UnitTestCase {

  public function testSandboxPlanSelection(): void {
    $offer = new LmsOffer([
      'id' => 'monthly',
      'label' => 'Monthly',
      'paypal_environment' => 'sandbox',
      'paypal_sandbox_plan_id' => 'P-BASE-SANDBOX',
      'paypal_sandbox_vip_plan_id' => 'P-VIP-SANDBOX',
      'paypal_live_plan_id' => 'P-BASE-LIVE',
      'paypal_live_vip_plan_id' => 'P-VIP-LIVE',
      'vip_enabled' => TRUE,
      'vip_surcharge_number' => '12.00',
      'vip_surcharge_currency' => 'USD',
    ], 'commerce_lms_offer');

    self::assertSame('P-BASE-SANDBOX', $offer->getActivePayPalPlanId());
    self::assertSame('P-VIP-SANDBOX', $offer->getActivePayPalVipPlanId());
    self::assertTrue($offer->isVipEnabled());
    self::assertSame('12.00', $offer->getVipSurchargeNumber());
  }

  public function testLivePlanSelection(): void {
    $offer = new LmsOffer([
      'id' => 'annual',
      'label' => 'Annual',
      'paypal_environment' => 'live',
      'paypal_sandbox_plan_id' => 'P-BASE-SANDBOX',
      'paypal_sandbox_vip_plan_id' => 'P-VIP-SANDBOX',
      'paypal_live_plan_id' => 'P-BASE-LIVE',
      'paypal_live_vip_plan_id' => 'P-VIP-LIVE',
    ], 'commerce_lms_offer');

    self::assertSame('P-BASE-LIVE', $offer->getActivePayPalPlanId());
    self::assertSame('P-VIP-LIVE', $offer->getActivePayPalVipPlanId());
  }

}
