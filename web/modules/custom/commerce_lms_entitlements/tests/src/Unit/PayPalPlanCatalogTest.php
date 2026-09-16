<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Unit;

use Drupal\commerce_lms_entitlements\Entity\LmsOffer;
use Drupal\commerce_lms_entitlements\PayPalPlanCatalog;
use Drupal\commerce_price\Price;
use Drupal\Tests\UnitTestCase;

/** Tests standalone PayPal plan validation. */
final class PayPalPlanCatalogTest extends UnitTestCase {

  public function testStandardVipPlanValidationRejectsTrialCycles(): void {
    $catalog = (new \ReflectionClass(PayPalPlanCatalog::class))->newInstanceWithoutConstructor();
    $offer = new LmsOffer([
      'id' => 'monthly',
      'billing_interval' => 'monthly',
    ], 'commerce_lms_offer');
    $base_cycle = [
      'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
      'tenure_type' => 'REGULAR',
      'sequence' => 1,
      'total_cycles' => 0,
      'pricing_scheme' => [
        'fixed_price' => ['value' => '19', 'currency_code' => 'USD'],
      ],
    ];
    $standard = [
      'product_id' => 'PROD-TEST',
      'billing_cycles' => [$base_cycle],
    ];
    $vip = [
      'status' => 'ACTIVE',
      'product_id' => 'PROD-TEST',
      'quantity_supported' => FALSE,
      'billing_cycles' => [
        array_replace($base_cycle, ['tenure_type' => 'TRIAL']),
        array_replace($base_cycle, [
          'pricing_scheme' => [
            'fixed_price' => ['value' => '31', 'currency_code' => 'USD'],
          ],
        ]),
      ],
    ];

    self::assertSame(
      ['the plan must contain one REGULAR billing cycle and no TRIAL cycles'],
      $catalog->validateStandardVipPlan($offer, $vip, $standard, new Price('31', 'USD')),
    );

    $vip['billing_cycles'] = [$vip['billing_cycles'][1]];
    self::assertSame([], $catalog->validateStandardVipPlan($offer, $vip, $standard, new Price('31', 'USD')));
  }

}
