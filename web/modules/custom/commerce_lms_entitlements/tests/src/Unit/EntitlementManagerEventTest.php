<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Unit;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\Tests\UnitTestCase;

/** Tests classification of verified PayPal webhook events. */
final class EntitlementManagerEventTest extends UnitTestCase {

  private EntitlementManager $manager;

  protected function setUp(): void {
    parent::setUp();
    $this->manager = (new \ReflectionClass(EntitlementManager::class))->newInstanceWithoutConstructor();
  }

  public function testPlanEventIsNotASubscriptionEvent(): void {
    self::assertFalse($this->manager->isSubscriptionEvent([
      'event_type' => 'BILLING.PLAN.CREATED',
      'resource' => ['id' => 'P-PLAN'],
    ]));
  }

  public function testSubscriptionLifecycleEventIsAccepted(): void {
    self::assertTrue($this->manager->isSubscriptionEvent([
      'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
      'resource' => ['id' => 'I-SUBSCRIPTION'],
    ]));
  }

  public function testStoredPaymentEventIsAccepted(): void {
    self::assertTrue($this->manager->isSubscriptionEvent([
      'event_type' => 'PAYMENT.SALE.COMPLETED',
      'payload' => json_encode([
        'event_type' => 'PAYMENT.SALE.COMPLETED',
        'resource' => ['billing_agreement_id' => 'I-SUBSCRIPTION'],
      ], JSON_THROW_ON_ERROR),
    ]));
  }

}
