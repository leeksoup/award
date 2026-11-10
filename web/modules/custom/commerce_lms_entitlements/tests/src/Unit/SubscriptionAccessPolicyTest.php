<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Unit;

use Drupal\commerce_lms_entitlements\SubscriptionAccessPolicy;
use PHPUnit\Framework\TestCase;

/** Tests subscription identity, approved plans, and paid tier boundaries. */
final class SubscriptionAccessPolicyTest extends TestCase {

  private const NOW = 1_700_000_000;
  private const TIERS = ['P-BASE' => 'base', 'P-VIP' => 'vip', 'P-CAMPAIGN' => 'vip'];
  private const STANDARD = ['base' => 'P-BASE', 'vip' => 'P-VIP'];

  /** Valid configured plans retain their approved tier. */
  public function testConfiguredPlans(): void {
    foreach (self::TIERS as $plan => $tier) {
      $result = $this->evaluate(['paypal_plan_id' => $plan], ['plan_id' => $plan]);
      self::assertSame($plan, $result['plan_id']);
      self::assertSame($tier === 'vip', $result['vip_active']);
      self::assertFalse($result['paid_change']);
    }
  }

  /** @dataProvider invalidIdentityProvider */
  public function testInvalidIdentity(array $entitlement, array $remote): void {
    $this->expectException(\DomainException::class);
    $this->evaluate($entitlement, $remote);
  }

  public static function invalidIdentityProvider(): array {
    return [
      'missing subscription' => [[], ['id' => '']],
      'other subscription' => [[], ['id' => 'I-OTHER']],
      'unlinked entitlement' => [['paypal_subscription_id' => ''], []],
      'empty remote plan' => [[], ['plan_id' => '']],
      'unknown plan' => [[], ['plan_id' => 'P-UNKNOWN']],
      'wrong environment' => [[], ['plan_id' => 'P-LIVE']],
      'wrong product' => [[], ['plan_id' => 'P-OTHER-PRODUCT']],
      'empty approved plan' => [['paypal_plan_id' => ''], []],
      'unconfigured approved plan' => [['paypal_plan_id' => 'P-OLD'], ['plan_id' => 'P-OLD']],
      'unapproved VIP upgrade' => [[], ['plan_id' => 'P-VIP']],
      'unknown status' => [[], ['status' => 'UNKNOWN']],
      'missing status' => [[], ['status' => '']],
      'lifetime entitlement' => [['purchase_type' => 'lifetime'], []],
    ];
  }

  /** A remote target alone records approval but cannot activate an upgrade. */
  public function testUpgradeWaitsForPaidBoundary(): void {
    $change = $this->change();
    foreach ([NULL, 'invalid', gmdate('c', self::NOW - 101), gmdate('c', self::NOW + 1)] as $time) {
      $result = $this->evaluate([], ['plan_id' => 'P-VIP', 'billing_info' => ['last_payment' => ['time' => $time]]], $change);
      self::assertTrue($result['on_target']);
      self::assertFalse($result['paid_change']);
      self::assertFalse($result['vip_active']);
      self::assertSame('P-BASE', $result['plan_id']);
    }
    $result = $this->evaluate([], ['plan_id' => 'P-VIP'], $change);
    self::assertTrue($result['paid_change']);
    self::assertTrue($result['vip_active']);
    self::assertSame('P-VIP', $result['plan_id']);
  }

  /** Downgrades preserve paid VIP until the target plan has billed. */
  public function testDowngradeWaitsForTargetPayment(): void {
    $change = array_replace($this->change(), [
      'from_tier' => 'vip', 'to_tier' => 'base',
      'from_plan_id' => 'P-VIP', 'target_plan_id' => 'P-BASE',
      'status' => 'approved',
    ]);
    $result = $this->evaluate(['paypal_plan_id' => 'P-VIP'], ['plan_id' => 'P-VIP'], $change);
    self::assertTrue($result['vip_active']);
    self::assertFalse($result['paid_change']);
    $result = $this->evaluate(['paypal_plan_id' => 'P-VIP'], ['plan_id' => 'P-BASE', 'billing_info' => []], $change);
    self::assertTrue($result['vip_active']);
    $result = $this->evaluate(['paypal_plan_id' => 'P-VIP'], [], $change);
    self::assertFalse($result['vip_active']);
    self::assertTrue($result['paid_change']);
  }

  /** Billing evidence cannot finalize a revision on its old plan. */
  public function testOldPlanPaymentCannotFinalizeUpgrade(): void {
    $result = $this->evaluate([], [], array_replace($this->change(), ['status' => 'approved']));
    self::assertFalse($result['paid_change']);
    self::assertFalse($result['vip_active']);
    self::assertSame('P-BASE', $result['plan_id']);
  }

  /** A legacy revision with a prematurely stored target still waits to bill. */
  public function testLegacyApprovedUpgrade(): void {
    $change = array_replace($this->change(), ['status' => 'approved']);
    $entitlement = ['paypal_plan_id' => 'P-VIP', 'activated' => self::NOW - 500, 'vip_active' => 0];
    $result = $this->evaluate($entitlement, ['plan_id' => 'P-VIP', 'billing_info' => []], $change);
    self::assertSame('P-BASE', $result['plan_id']);
    self::assertFalse($result['vip_active']);
    $result = $this->evaluate($entitlement, ['plan_id' => 'P-VIP'], $change);
    self::assertSame('P-VIP', $result['plan_id']);
    self::assertTrue($result['vip_active']);
  }

  /** An abandoned legacy upgrade cannot activate a prematurely stored plan. */
  public function testUnverifiedLegacyVipCannotGrant(): void {
    $this->expectException(\DomainException::class);
    $this->evaluate(['paypal_plan_id' => 'P-VIP', 'activated' => self::NOW - 500, 'vip_active' => 0], ['plan_id' => 'P-VIP']);
  }

  /** A campaign can transition to the configured standard plan. */
  public function testCampaignRevision(): void {
    $change = array_replace($this->change(), [
      'from_tier' => 'vip', 'to_tier' => 'base',
      'from_plan_id' => 'P-CAMPAIGN', 'target_plan_id' => 'P-BASE',
    ]);
    $result = $this->evaluate(['paypal_plan_id' => 'P-CAMPAIGN'], [], $change);
    self::assertTrue($result['paid_change']);
    self::assertFalse($result['vip_active']);
  }

  /** @dataProvider invalidChangeProvider */
  public function testInvalidChangeCannotAuthorizeTarget(array $overrides): void {
    $this->expectException(\DomainException::class);
    $this->evaluate([], ['plan_id' => 'P-VIP'], array_replace($this->change(), $overrides));
  }

  public static function invalidChangeProvider(): array {
    return [
      'abandoned' => [['status' => 'abandoned']],
      'superseded' => [['status' => 'effective']],
      'failed' => [['status' => 'failed']],
      'other entitlement' => [['eid' => 2]],
      'stale source plan' => [['from_plan_id' => 'P-OLD']],
      'wrong source tier' => [['from_tier' => 'vip']],
      'invalid target tier' => [['to_tier' => 'admin']],
      'same tier' => [['to_tier' => 'base']],
      'unconfigured target' => [['target_plan_id' => 'P-UNKNOWN']],
      'wrong environment' => [['target_plan_id' => 'P-LIVE']],
      'missing request' => [['requested' => 0]],
      'future request' => [['requested' => self::NOW + 1]],
      'missing boundary' => [['effective' => NULL]],
      'invalid boundary' => [['effective' => self::NOW - 201]],
    ];
  }

  /** Suspended and cancelled subscriptions cannot finish an upgrade. */
  public function testInactiveSubscriptionCannotFinalizeChange(): void {
    foreach (['SUSPENDED', 'CANCELLED', 'EXPIRED', 'APPROVAL_PENDING', 'APPROVED'] as $status) {
      $result = $this->evaluate([], ['plan_id' => 'P-VIP', 'status' => $status], $this->change());
      self::assertFalse($result['paid_change']);
      self::assertFalse($result['vip_active']);
    }
  }

  private function evaluate(array $entitlement = [], array $remote = [], ?array $change = NULL): array {
    return SubscriptionAccessPolicy::evaluate(
      array_replace(['eid' => 1, 'purchase_type' => 'recurring', 'paypal_subscription_id' => 'I-SUB', 'paypal_plan_id' => 'P-BASE'], $entitlement),
      array_replace(['id' => 'I-SUB', 'plan_id' => 'P-BASE', 'status' => 'ACTIVE', 'billing_info' => ['last_payment' => ['time' => gmdate('c', self::NOW - 100)]]], $remote),
      self::TIERS,
      self::STANDARD,
      $change,
      self::NOW,
    );
  }

  private function change(): array {
    return [
      'id' => 1, 'eid' => 1, 'status' => 'approval_pending',
      'from_tier' => 'base', 'to_tier' => 'vip',
      'from_plan_id' => 'P-BASE', 'target_plan_id' => 'P-VIP',
      'requested' => self::NOW - 200, 'effective' => self::NOW - 100,
    ];
  }

}
