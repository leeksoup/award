<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Unit;

use Drupal\commerce_lms_entitlements\Entity\LmsSubscriptionCampaign;
use Drupal\commerce_price\Price;
use Drupal\Tests\UnitTestCase;

/** Tests campaign behavior and offer-specific PayPal plan selection. */
final class LmsSubscriptionCampaignTest extends UnitTestCase {

  public function testMultipleOfferMappings(): void {
    self::assertLessThanOrEqual(32, strlen(LmsSubscriptionCampaign::ENTITY_TYPE_ID));
    $campaign = new LmsSubscriptionCampaign([
      'id' => 'launch',
      'label' => 'Launch',
      'status' => TRUE,
      'behavior' => LmsSubscriptionCampaign::BEHAVIOR_FREE_VIP_LAUNCH,
      'terms' => 'The base subscription is discounted and VIP is included for the introductory period.',
      'offer_mappings' => [
        'monthly' => [
          'intro_cycles' => 12,
          'base_intro_number' => '',
          'base_intro_currency' => 'USD',
          'vip_intro_number' => '9.70',
          'vip_intro_currency' => 'USD',
          'paypal_sandbox_base_plan_id' => '',
          'paypal_sandbox_vip_plan_id' => 'P-MONTHLY-SANDBOX',
          'paypal_live_base_plan_id' => '',
          'paypal_live_vip_plan_id' => 'P-MONTHLY-LIVE',
        ],
        'annual' => [
          'intro_cycles' => 1,
          'base_intro_number' => '',
          'base_intro_currency' => 'USD',
          'vip_intro_number' => '97.00',
          'vip_intro_currency' => 'USD',
          'paypal_sandbox_base_plan_id' => '',
          'paypal_sandbox_vip_plan_id' => 'P-ANNUAL-SANDBOX',
          'paypal_live_base_plan_id' => '',
          'paypal_live_vip_plan_id' => 'P-ANNUAL-LIVE',
        ],
      ],
    ], LmsSubscriptionCampaign::ENTITY_TYPE_ID);

    self::assertTrue($campaign->forcesVip());
    self::assertSame(12, $campaign->getIntroCycles('monthly'));
    self::assertSame(1, $campaign->getIntroCycles('annual'));
    self::assertSame('9.70', $campaign->getIntroPrice('monthly', TRUE)?->getNumber());
    self::assertSame('97.00', $campaign->getIntroPrice('annual', TRUE)?->getNumber());
    self::assertNull($campaign->getIntroPrice('monthly', FALSE));
    self::assertSame('P-MONTHLY-SANDBOX', $campaign->getPayPalPlanId('monthly', 'sandbox', TRUE));
    self::assertSame('P-ANNUAL-LIVE', $campaign->getPayPalPlanId('annual', 'live', TRUE));
    self::assertNull($campaign->getOfferMapping('quarterly'));
    self::assertTrue($campaign->hasValidLaunchIntroPrice('monthly', new Price('19.00', 'USD')));
    self::assertTrue($campaign->hasValidLaunchIntroPrice('annual', new Price('197.00', 'USD')));
    self::assertFalse($campaign->hasValidLaunchIntroPrice('monthly', new Price('9.69', 'USD')));
    self::assertFalse($campaign->hasValidLaunchIntroPrice('monthly', new Price('19.00', 'CAD')));
  }

  public function testIntroDiscountTierSelection(): void {
    $campaign = new LmsSubscriptionCampaign([
      'id' => 'intro',
      'label' => 'Intro',
      'behavior' => LmsSubscriptionCampaign::BEHAVIOR_INTRO_DISCOUNT,
      'offer_mappings' => [
        'monthly' => [
          'intro_cycles' => 3,
          'base_intro_number' => '20.00',
          'base_intro_currency' => 'USD',
          'vip_intro_number' => '30.00',
          'vip_intro_currency' => 'USD',
          'paypal_live_base_plan_id' => 'P-BASE',
          'paypal_live_vip_plan_id' => 'P-VIP',
        ],
      ],
    ], LmsSubscriptionCampaign::ENTITY_TYPE_ID);

    self::assertFalse($campaign->forcesVip());
    self::assertSame('20.00', $campaign->getIntroPrice('monthly', FALSE)?->getNumber());
    self::assertSame('30.00', $campaign->getIntroPrice('monthly', TRUE)?->getNumber());
    self::assertSame('P-BASE', $campaign->getPayPalPlanId('monthly', 'live', FALSE));
    self::assertSame('P-VIP', $campaign->getPayPalPlanId('monthly', 'live', TRUE));
  }

}
