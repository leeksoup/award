<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Kernel;

use Drupal\commerce_lms_entitlements\Entity\LmsOffer;
use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\EntitlementMembershipManager;
use Drupal\commerce_lms_entitlements\Mailer\EntitlementMailerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\UserInterface;
use Psr\Log\NullLogger;

/** Tests persisted subscription and membership transitions. */
final class SubscriptionReconciliationTest extends KernelTestBase {

  protected static $modules = ['system'];

  private const NOW = 1_700_000_000;

  private int $now = self::NOW;
  private Connection $database;
  private EntitlementManager $manager;
  private string $gatewayMode = 'test';
  private bool $vipMember = TRUE;
  private bool $failRemoval = FALSE;

  protected function setUp(): void {
    parent::setUp();
    $this->database = $this->container->get('database');
    require_once dirname(__DIR__, 3) . '/commerce_lms_entitlements.install';
    $schema = commerce_lms_entitlements_schema();
    foreach (['commerce_lms_entitlement', 'commerce_lms_plan_change', 'commerce_lms_entitlement_membership', 'commerce_lms_vip_booking'] as $table) {
      $this->database->schema()->createTable($table, $schema[$table]);
    }

    $offer = new LmsOffer([
      'id' => 'monthly',
      'label' => 'Monthly',
      'paypal_environment' => 'live',
      'paypal_sandbox_gateway_id' => 'sandbox',
      'paypal_sandbox_plan_id' => 'P-BASE',
      'paypal_sandbox_vip_plan_id' => 'P-VIP',
      'paypal_live_gateway_id' => 'live',
      'paypal_live_plan_id' => 'P-LIVE',
    ], 'commerce_lms_offer');
    $order = new class {
      public function get(string $field): object {
        return (object) ['target_id' => 'sandbox'];
      }
    };
    $gateway = new class(fn (): string => $this->gatewayMode) {
      public function __construct(private \Closure $mode) {}
      public function getPluginId(): string {
        return 'paypal_checkout_subscriptions';
      }
      public function getPlugin(): object {
        return $this;
      }
      public function getMode(): string {
        return ($this->mode)();
      }
      public function getPluginConfiguration(): array {
        return [];
      }
    };
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(7);
    $group = $this->createMock(GroupInterface::class);
    $group->method('getMember')->willReturnCallback(fn (): object|false => $this->vipMember ? (object) [] : FALSE);
    $group->method('removeMember')->willReturnCallback(function (): void {
      if ($this->failRemoval) {
        throw new \RuntimeException('Simulated Group removal failure.');
      }
      $this->vipMember = FALSE;
    });

    $storages = [];
    foreach (['commerce_lms_offer' => $offer, 'commerce_order' => $order, 'commerce_payment_gateway' => $gateway, 'user' => $user, 'group' => $group] as $type => $entity) {
      $storages[$type] = $this->createMock(EntityStorageInterface::class);
      $storages[$type]->method('load')->willReturn($entity);
    }
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(static fn (string $type): EntityStorageInterface => $storages[$type]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn (): int => $this->now);
    // Force a failed remote refresh after cron has checked the local deadline.
    $sdk_factory = new class {
      public function get(array $configuration): object {
        throw new \RuntimeException('Simulated PayPal outage.');
      }
    };
    $this->manager = new EntitlementManager(
      $this->database,
      $entity_type_manager,
      new EntitlementMembershipManager($this->database, $entity_type_manager, $time),
      $this->container->get('config.factory'),
      $this->createMock(QueueFactory::class),
      $time,
      new NullLogger(),
      $sdk_factory,
      $this->createMock(EntitlementMailerInterface::class),
      $this->createMock(UrlGeneratorInterface::class),
    );
  }

  /** An unexpected plan cannot activate a purchase; valid data recovers it. */
  public function testPendingMismatchRecovers(): void {
    $eid = $this->insertEntitlement();
    $this->reject($eid, $this->remote('P-LIVE'));
    $row = $this->manager->load($eid);
    self::assertSame('plan_mismatch', $row['status']);
    self::assertSame('P-BASE', $row['paypal_plan_id']);
    self::assertNull($row['access_through']);
    self::assertNull($row['activated']);
    $this->manager->applyRemoteSubscription($row, $this->remote('P-BASE'));
    self::assertSame('active', $this->manager->load($eid)['status']);
  }

  /** Gateway mode is verified against the historical checkout environment. */
  public function testWrongGatewayModeCannotActivate(): void {
    $eid = $this->insertEntitlement();
    $this->gatewayMode = 'live';
    $this->reject($eid, $this->remote('P-BASE'));
    self::assertSame('plan_mismatch', $this->manager->load($eid)['status']);
  }

  /** An unexpected base plan cannot extend paid VIP through a new boundary. */
  public function testMismatchExpiresDuringPayPalOutage(): void {
    $eid = $this->insertEntitlement([
      'status' => 'active', 'paypal_plan_id' => 'P-VIP', 'vip_active' => 1,
      'activated' => self::NOW - 500, 'access_through' => self::NOW + 10,
    ]);
    $this->insertVipMembership($eid);
    $this->reject($eid, $this->remote('P-BASE'));
    self::assertSame(self::NOW + 10, (int) $this->manager->load($eid)['access_through']);
    self::assertTrue($this->vipMember);
    $this->now += 10;
    $this->manager->reconcile();
    self::assertSame('plan_mismatch', $this->manager->load($eid)['status']);
    self::assertFalse($this->vipMember);
  }

  /** Paid downgrade and membership removal must both succeed or retry. */
  public function testPaidDowngradeRetriesFailedVipRemoval(): void {
    $eid = $this->insertEntitlement([
      'status' => 'active', 'paypal_plan_id' => 'P-VIP', 'vip_active' => 1,
      'activated' => self::NOW - 500,
    ]);
    $this->insertVipMembership($eid);
    $this->database->insert('commerce_lms_plan_change')->fields([
      'eid' => $eid, 'from_tier' => 'vip', 'to_tier' => 'base',
      'from_plan_id' => 'P-VIP', 'target_plan_id' => 'P-BASE',
      'state_hash' => hash('sha256', 'test'), 'status' => 'approved',
      'requested' => self::NOW - 200, 'effective' => self::NOW - 100,
      'changed' => self::NOW - 200,
    ])->execute();
    $this->failRemoval = TRUE;
    try {
      $this->manager->applyRemoteSubscription($this->manager->load($eid), $this->remote('P-BASE'));
      self::fail('A failed VIP removal must propagate.');
    }
    catch (\RuntimeException $e) {
      self::assertSame('Simulated Group removal failure.', $e->getMessage());
    }
    self::assertSame('P-VIP', $this->manager->load($eid)['paypal_plan_id']);
    self::assertSame('approved', $this->manager->pendingPlanChange($eid)['status']);
    self::assertTrue($this->vipMember);

    $this->failRemoval = FALSE;
    $this->manager->applyRemoteSubscription($this->manager->load($eid), $this->remote('P-BASE'));
    $current = $this->manager->load($eid);
    self::assertSame('P-BASE', $current['paypal_plan_id']);
    self::assertSame(0, (int) $current['vip_active']);
    self::assertNull($this->manager->pendingPlanChange($eid));
    self::assertFalse($this->vipMember);
  }

  private function insertEntitlement(array $overrides = []): int {
    return (int) $this->database->insert('commerce_lms_entitlement')->fields(array_replace([
      'offer_id' => 'monthly', 'purchase_type' => 'recurring',
      'purchaser_uid' => 7, 'learner_uid' => 7, 'order_id' => 1,
      'paypal_subscription_id' => 'I-SUB', 'paypal_plan_id' => 'P-BASE',
      'status' => 'pending', 'created' => self::NOW - 500, 'changed' => self::NOW - 500,
    ], $overrides))->execute();
  }

  private function insertVipMembership(int $eid): void {
    $this->database->insert('commerce_lms_entitlement_membership')->fields([
      'eid' => $eid, 'class_id' => 23, 'uid' => 7, 'membership_created' => 1,
      'benefit' => 'vip', 'active' => 1, 'created' => self::NOW - 500,
    ])->execute();
  }

  private function remote(string $plan): array {
    return [
      'id' => 'I-SUB', 'plan_id' => $plan, 'status' => 'ACTIVE',
      'billing_info' => [
        'next_billing_time' => gmdate('c', self::NOW + 3600),
        'last_payment' => ['time' => gmdate('c', self::NOW - 100)],
      ],
    ];
  }

  private function reject(int $eid, array $remote): void {
    try {
      $this->manager->applyRemoteSubscription($this->manager->load($eid), $remote);
      self::fail('The remote subscription should have been rejected.');
    }
    catch (\DomainException) {
      // Check persisted state after the expected rejection.
    }
  }

}
