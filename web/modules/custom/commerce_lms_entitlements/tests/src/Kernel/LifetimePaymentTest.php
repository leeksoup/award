<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Kernel;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\EntitlementMembershipManager;
use Drupal\commerce_lms_entitlements\SubscriptionPlanSelection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Routing\UrlGeneratorInterface;
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
   * Orders available to the activation-time learner resolver.
   *
   * @var object[]
   */
  private array $orders = [];

  /**
   * Invitation messages accepted by the test mail backend.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $sentInvitations = [];

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
        'subscription_campaign_id' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
        'promotion_uuid' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
        'coupon_uuid' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
        'checkout_token' => ['type' => 'varchar', 'length' => 80, 'not null' => FALSE],
        'checkout_snapshot' => ['type' => 'blob', 'size' => 'big', 'not null' => FALSE],
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
      'unique keys' => [
        'order' => ['order_id'],
        'checkout_token' => ['checkout_token'],
      ],
    ]);
    $this->database->schema()->createTable('commerce_lms_entitlement_invitation', [
      'fields' => [
        'id' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'email' => ['type' => 'varchar', 'length' => 254, 'not null' => TRUE],
        'token_hash' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'claimed_uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'expires' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
      ],
      'primary key' => ['id'],
      'unique keys' => ['token_hash' => ['token_hash']],
    ]);

    $this->offer = new LifetimeOfferDouble();
    $offer_storage = $this->createMock(EntityStorageInterface::class);
    $offer_storage->method('loadByProperties')->willReturn([$this->offer]);
    $payment_storage = $this->createMock(EntityStorageInterface::class);
    $payment_storage->method('loadByProperties')
      ->willReturnCallback(fn (array $properties): array => $this->completedPayments);
    $order_storage = $this->createMock(EntityStorageInterface::class);
    $order_storage->method('load')
      ->willReturnCallback(fn (int $id): ?object => $this->orders[$id] ?? NULL);
    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user_storage->method('loadByProperties')->willReturn([]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->willReturnCallback(static function (
        string $entity_type_id,
      ) use ($offer_storage, $payment_storage, $order_storage, $user_storage): EntityStorageInterface {
        return match ($entity_type_id) {
          'commerce_lms_offer' => $offer_storage,
          'commerce_payment' => $payment_storage,
          'commerce_order' => $order_storage,
          'user' => $user_storage,
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
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->method('mail')->willReturnCallback(function (
      string $module,
      string $key,
      string $to,
      string $langcode,
      array $params,
    ): array {
      $this->sentInvitations[] = compact('module', 'key', 'to', 'langcode', 'params');
      return ['result' => TRUE];
    });
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getDefaultLanguage')->willReturn($language);
    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')
      ->willReturn('https://example.com/commerce-lms-entitlements/invitation/token');
    $this->manager = new EntitlementManager(
      $this->database,
      $entity_type_manager,
      $membership_manager,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(QueueFactory::class),
      $time,
      $this->createMock(LoggerInterface::class),
      new \stdClass(),
      $mail_manager,
      $language_manager,
      $url_generator,
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
   * A retry cannot replace the immutable checkout token or cart snapshot.
   */
  public function testRecurringCheckoutSealIsImmutable(): void {
    $order = new LifetimeOrderDouble(109, FALSE);
    $selection = new SubscriptionPlanSelection('lifetime', 'P-SEALED', FALSE);
    $this->manager->ensureEntitlement($order, $this->offer, $selection);
    $this->database->update('commerce_lms_entitlement')
      ->fields(['purchase_type' => 'recurring'])
      ->condition('order_id', 109)
      ->execute();

    $entitlement = $this->manager->loadByOrder(109);
    $first = $this->manager->sealRecurringCheckout($entitlement, [
      'version' => 1,
      'total' => ['number' => '97', 'currency_code' => 'USD'],
    ]);
    self::assertStringStartsWith('lms-', $first['checkout_token']);
    self::assertSame($first['eid'], $this->manager->loadByCheckoutToken($first['checkout_token'])['eid']);

    $second = $this->manager->sealRecurringCheckout($first, [
      'version' => 1,
      'total' => ['number' => '999', 'currency_code' => 'USD'],
    ]);
    self::assertSame($first['checkout_token'], $second['checkout_token']);
    self::assertSame($first['checkout_snapshot'], $second['checkout_snapshot']);
    self::assertStringContainsString('"97"', $second['checkout_snapshot']);
    self::assertStringNotContainsString('"999"', $second['checkout_snapshot']);

    self::assertSame(
      $first['eid'],
      $this->manager->ensureEntitlement($order, $this->offer, $selection)['eid'],
    );
    $this->expectException(\DomainException::class);
    $this->expectExceptionMessage('The subscription selection changed after PayPal approval began.');
    $this->manager->ensureEntitlement(
      $order,
      $this->offer,
      new SubscriptionPlanSelection('lifetime', 'P-CHANGED', FALSE),
    );
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
   * A new learner is invited once, and only after completed payment.
   */
  public function testInvitationWaitsForActivation(): void {
    $order = new LifetimeOrderDouble(
      120,
      FALSE,
      ['email' => 'new.learner@example.com'],
    );
    $this->orders[120] = $order;
    $payment = new LifetimePaymentDouble(220, $order, 'completed', 'CAPTURE-20');

    $pending = $this->manager->ensureEntitlement($order, $this->offer);
    self::assertSame('pending', $pending['status']);
    self::assertNull($pending['invitation_id']);
    self::assertSame(0, $this->invitationCount());
    self::assertSame([], $this->sentInvitations);

    $this->manager->syncCompletedPayment($payment);
    self::assertSame(0, $this->invitationCount());
    self::assertSame([], $this->sentInvitations);

    $order->setPaid(TRUE);
    $this->manager->syncCompletedPayment($payment);
    $active = $this->entitlementForOrder(120);
    self::assertSame('active', $active['status']);
    self::assertNotEmpty($active['invitation_id']);
    self::assertSame(1, $this->invitationCount());
    self::assertCount(1, $this->sentInvitations);
    self::assertSame('new.learner@example.com', $this->sentInvitations[0]['to']);
    self::assertTrue($this->sentInvitations[0]['params']['__email']);

    $this->manager->syncCompletedPayment($payment);
    self::assertSame(1, $this->invitationCount());
    self::assertCount(1, $this->sentInvitations);
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

  /**
   * Counts generated learner invitations.
   */
  private function invitationCount(): int {
    return (int) $this->database
      ->select('commerce_lms_entitlement_invitation', 'i')
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
    private readonly ?array $learner = NULL,
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
      ? ($this->learner ?? ['invitation_id' => 'invitation-' . $this->orderId])
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
