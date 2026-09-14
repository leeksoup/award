<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Kernel;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\EntitlementMembershipManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the fully-paid lifetime entitlement boundary.
 */
#[Group('commerce_lms_entitlements')]
final class LifetimePaymentTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Fixed activation timestamp.
   */
  private const REQUEST_TIME = 1_700_000_000;

  /**
   * The kernel test database connection.
   */
  private Connection $database;

  /**
   * The service under test.
   */
  private EntitlementManager $manager;

  /**
   * The lifetime offer returned for test orders.
   */
  private object $offer;

  /**
   * Completed payments visible to the order-update path.
   *
   * @var object[]
   */
  private array $completedPayments = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->container->get('database');
    $this->database->schema()->createTable('commerce_lms_entitlement', [
      'fields' => [
        'eid' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'offer_id' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'purchase_type' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE],
        'purchaser_uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'learner_uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'invitation_id' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
        'order_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'payment_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'paypal_subscription_id' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
        'paypal_plan_id' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
        'vip_selected' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
        'vip_active' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
        'initial_capture_id' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
        'refund_id' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
        'status' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => 'pending'],
        'activated' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'access_through' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'guarantee_requested' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
      ],
      'primary key' => ['eid'],
      'unique keys' => ['order' => ['order_id']],
    ]);

    $this->offer = new LifetimeOfferDouble();
    $offer_storage = $this->createMock(EntityStorageInterface::class);
    $offer_storage->method('loadByProperties')->willReturn([$this->offer]);
    $payment_storage = $this->createMock(EntityStorageInterface::class);
    $payment_storage->method('loadByProperties')
      ->willReturnCallback(fn (array $properties): array => $this->completedPayments);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->willReturnCallback(static function (
        string $entity_type_id,
      ) use ($offer_storage, $payment_storage): EntityStorageInterface {
        return match ($entity_type_id) {
          'commerce_lms_offer' => $offer_storage,
          'commerce_payment' => $payment_storage,
          default => throw new \LogicException('Unexpected storage: ' . $entity_type_id),
        };
      });

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::REQUEST_TIME);
    $membership_manager = new EntitlementMembershipManager(
      $this->database,
      $entity_type_manager,
      $time,
    );
    $this->manager = new EntitlementManager(
      $this->database,
      $entity_type_manager,
      $membership_manager,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(QueueFactory::class),
      $time,
      $this->createMock(LoggerInterface::class),
      new \stdClass(),
    );
  }

  /**
   * A completed partial payment cannot activate access.
   */
  public function testPartialPaymentWaitsForFullyPaidOrder(): void {
    $order = new LifetimeOrderDouble(101, FALSE);
    $payment = new LifetimePaymentDouble(201, $order, 'completed', 'CAPTURE-1');
    $this->manager->ensureEntitlement($order, $this->offer);

    $this->manager->syncCompletedPayment($payment);
    self::assertSame('pending', $this->entitlementForOrder(101)['status']);

    $order->setPaid(TRUE);
    $this->manager->syncCompletedPayment($payment);
    $entitlement = $this->entitlementForOrder(101);
    self::assertSame('active', $entitlement['status']);
    self::assertSame('201', $entitlement['payment_id']);
    self::assertSame('CAPTURE-1', $entitlement['initial_capture_id']);
  }

  /**
   * The later Commerce order refresh can complete deferred activation.
   */
  public function testPaidOrderRefreshFindsCompletedPayment(): void {
    $order = new LifetimeOrderDouble(102, FALSE);
    $payment = new LifetimePaymentDouble(202, $order, 'completed', 'CAPTURE-2');
    $this->manager->ensureEntitlement($order, $this->offer);
    $this->manager->syncCompletedPayment($payment);

    $order->setPaid(TRUE);
    $this->completedPayments = [$payment];
    $this->manager->syncPaidLifetimeOrder($order);

    $entitlement = $this->entitlementForOrder(102);
    self::assertSame('active', $entitlement['status']);
    self::assertSame((string) self::REQUEST_TIME, $entitlement['activated']);
  }

  /**
   * A payment returned for another order cannot activate this entitlement.
   */
  public function testPaymentFromAnotherOrderCannotActivate(): void {
    $order = new LifetimeOrderDouble(103, TRUE);
    $other_order = new LifetimeOrderDouble(104, TRUE);
    $this->manager->ensureEntitlement($order, $this->offer);
    $this->completedPayments = [
      new LifetimePaymentDouble(203, $other_order, 'completed', 'CAPTURE-3'),
    ];

    $this->manager->syncPaidLifetimeOrder($order);

    self::assertSame('pending', $this->entitlementForOrder(103)['status']);
  }

  /**
   * Repeated completed-payment notifications do not replace audit identity.
   */
  public function testRepeatedProcessingIsIdempotent(): void {
    $order = new LifetimeOrderDouble(105, TRUE);
    $first = new LifetimePaymentDouble(204, $order, 'completed', 'CAPTURE-4');
    $second = new LifetimePaymentDouble(205, $order, 'completed', 'CAPTURE-5');

    $this->manager->syncCompletedPayment($first);
    $this->manager->syncCompletedPayment($first);
    $this->manager->syncCompletedPayment($second);

    $entitlement = $this->entitlementForOrder(105);
    self::assertSame('204', $entitlement['payment_id']);
    self::assertSame('CAPTURE-4', $entitlement['initial_capture_id']);
    self::assertSame(1, $this->entitlementCount(105));
  }

  /**
   * Non-completed payment states never activate lifetime access.
   */
  public function testNonCompletedPaymentsDoNotActivate(): void {
    foreach (['pending', 'failed', 'voided', 'refunded'] as $offset => $state) {
      $order_id = 110 + $offset;
      $order = new LifetimeOrderDouble($order_id, TRUE);
      $this->manager->ensureEntitlement($order, $this->offer);

      $this->manager->syncCompletedPayment(new LifetimePaymentDouble(
        210 + $offset,
        $order,
        $state,
        'CAPTURE-' . $state,
      ));

      self::assertSame('pending', $this->entitlementForOrder($order_id)['status']);
    }
  }

  /**
   * Returns the entitlement row for an order.
   */
  private function entitlementForOrder(int $order_id): array {
    $entitlement = $this->manager->loadByOrder($order_id);
    self::assertIsArray($entitlement);
    return $entitlement;
  }

  /**
   * Counts entitlement rows for an order.
   */
  private function entitlementCount(int $order_id): int {
    return (int) $this->database
      ->select('commerce_lms_entitlement', 'e')
      ->condition('order_id', $order_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}

/**
 * Minimal lifetime offer test double.
 */
final class LifetimeOfferDouble {

  public function id(): string {
    return 'lifetime';
  }

  public function getPurchaseType(): string {
    return 'lifetime';
  }

  public function getPaymentGatewayId(): string {
    return 'paypal_checkout';
  }

  public function getCourseClassMap(): array {
    return [];
  }

}

/**
 * Minimal Commerce order test double.
 */
final class LifetimeOrderDouble {

  public function __construct(
    private readonly int $orderId,
    private bool $paid,
  ) {}

  public function id(): int {
    return $this->orderId;
  }

  public function isPaid(): bool {
    return $this->paid;
  }

  public function setPaid(bool $paid): void {
    $this->paid = $paid;
  }

  public function getItems(): array {
    return [new LifetimeOrderItemDouble()];
  }

  public function getCustomerId(): int {
    return 7;
  }

  public function getData(string $key): mixed {
    return $key === 'commerce_lms_learner'
      ? ['invitation_id' => 'invitation-' . $this->orderId]
      : NULL;
  }

}

/**
 * Minimal Commerce order-item test double.
 */
final class LifetimeOrderItemDouble {

  public function getQuantity(): string {
    return '1';
  }

  public function getPurchasedEntityId(): int {
    return 99;
  }

}

/**
 * Minimal Commerce payment test double.
 */
final class LifetimePaymentDouble {

  public function __construct(
    private readonly int $paymentId,
    private readonly LifetimeOrderDouble $order,
    private readonly string $state,
    private readonly string $remoteId,
  ) {}

  public function id(): int {
    return $this->paymentId;
  }

  public function getOrder(): LifetimeOrderDouble {
    return $this->order;
  }

  public function getState(): object {
    return (object) ['value' => $this->state];
  }

  public function getPaymentGateway(): object {
    return new class {
      public function id(): string {
        return 'paypal_checkout';
      }
    };
  }

  public function getRemoteId(): string {
    return $this->remoteId;
  }

}
