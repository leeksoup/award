<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Kernel;

use Drupal\commerce_lms_entitlements\EntitlementMembershipManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests entitlement-backed Group membership ownership and revocation.
 */
#[Group('commerce_lms_entitlements')]
final class EntitlementMembershipManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The kernel test database connection.
   */
  private Connection $database;

  /**
   * The service under test.
   */
  private EntitlementMembershipManager $manager;

  /**
   * Mock Class used by the service.
   */
  private GroupInterface $class;

  /**
   * Mock learner account used by the service.
   */
  private UserInterface $account;

  /**
   * Memberships keyed by account ID.
   *
   * @var array<int, object>
   */
  private array $members = [];

  /**
   * Whether the Class mock should reject membership removal.
   */
  private bool $failRemoval = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->container->get('database');
    $this->database->schema()->createTable('commerce_lms_entitlement_membership', [
      'fields' => [
        'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'eid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'class_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'membership_created' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
        'benefit' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'base'],
        'active' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 1],
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'entitlement_class_benefit' => ['eid', 'class_id', 'benefit'],
      ],
      'indexes' => [
        'class_user_active' => ['class_id', 'uid', 'active'],
      ],
    ]);

    $this->account = $this->createMock(UserInterface::class);
    $this->account->method('id')->willReturn(7);
    $this->class = $this->createMock(GroupInterface::class);
    $this->class->method('id')->willReturn(23);
    $this->class->method('getMember')->willReturnCallback(
      fn (UserInterface $account): object|false => $this->members[$account->id()] ?? FALSE,
    );
    $this->class->method('addMember')->willReturnCallback(
      function (UserInterface $account): void {
        $this->members[$account->id()] = (object) [
          'uid' => $account->id(),
        ];
      },
    );
    $this->class->method('removeMember')->willReturnCallback(
      function (UserInterface $account): void {
        if ($this->failRemoval) {
          throw new \RuntimeException('Simulated membership removal failure.');
        }
        unset($this->members[$account->id()]);
      },
    );

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')->willReturnCallback(
      fn (int|string $id): ?GroupInterface => (int) $id === $this->class->id()
        ? $this->class
        : NULL,
    );
    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user_storage->method('load')->willReturnCallback(
      fn (int|string $id): ?UserInterface => (int) $id === $this->account->id()
        ? $this->account
        : NULL,
    );
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(
      static fn (string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'group' => $group_storage,
        'user' => $user_storage,
        default => throw new \LogicException("Unexpected entity type: $entity_type_id"),
      },
    );
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_000);

    $this->manager = new EntitlementMembershipManager(
      $this->database,
      $entity_type_manager,
      $time,
    );
  }

  /**
   * Repeated grants retain ownership and final revocation removes access.
   */
  public function testRepeatedGrantPreservesOwnership(): void {
    $entitlement = ['eid' => 1];

    $this->manager->grantTargets($entitlement, $this->account, $this->targets(), 'base');
    self::assertArrayHasKey(7, $this->members);
    self::assertSame(1, (int) $this->ledger(1)['membership_created']);
    self::assertSame(1, (int) $this->ledger(1)['active']);

    $this->manager->grantTargets($entitlement, $this->account, $this->targets(), 'base');
    self::assertSame(1, (int) $this->ledger(1)['membership_created']);
    self::assertSame(1, (int) $this->ledger(1)['active']);

    $this->manager->revokeBenefit($entitlement);
    self::assertArrayNotHasKey(7, $this->members);
    self::assertSame(0, (int) $this->ledger(1)['active']);
  }

  /**
   * A pre-existing manual membership survives entitlement revocation.
   */
  public function testManualMembershipIsPreserved(): void {
    $this->members[7] = (object) ['uid' => 7];
    $entitlement = ['eid' => 2];

    $this->manager->grantTargets($entitlement, $this->account, $this->targets(), 'base');
    self::assertSame(0, (int) $this->ledger(2)['membership_created']);

    $this->manager->revokeBenefit($entitlement);
    self::assertArrayHasKey(7, $this->members);
    self::assertSame(0, (int) $this->ledger(2)['active']);
  }

  /**
   * Another active entitlement retains a shared module-created membership.
   */
  public function testOverlappingEntitlementsRetainAccessUntilLastRevocation(): void {
    $first = ['eid' => 3];
    $second = ['eid' => 4];
    $this->manager->grantTargets($first, $this->account, $this->targets(), 'base');
    $this->manager->grantTargets($second, $this->account, $this->targets(), 'base');

    self::assertSame(1, (int) $this->ledger(3)['membership_created']);
    self::assertSame(0, (int) $this->ledger(4)['membership_created']);

    $this->manager->revokeBenefit($first);
    self::assertArrayHasKey(7, $this->members);

    $this->manager->revokeBenefit($second);
    self::assertArrayNotHasKey(7, $this->members);
  }

  /**
   * Base and VIP benefit rows cannot remove each other's shared membership.
   */
  public function testBaseAndVipBenefitsRetainSharedAccess(): void {
    $entitlement = ['eid' => 5];
    $this->manager->grantTargets(
      $entitlement,
      $this->account,
      $this->targets(),
      'base',
    );
    $this->manager->grantTargets(
      $entitlement,
      $this->account,
      $this->targets(),
      'vip',
    );

    $this->manager->revokeBenefit($entitlement, 'vip');
    self::assertArrayHasKey(7, $this->members);

    $this->manager->revokeBenefit($entitlement, 'base');
    self::assertArrayNotHasKey(7, $this->members);
  }

  /**
   * Failed Group removal rolls back the ledger and permits a later retry.
   */
  public function testRemovalFailureLeavesLedgerRetryable(): void {
    $entitlement = ['eid' => 6];
    $this->manager->grantTargets($entitlement, $this->account, $this->targets(), 'base');
    $this->failRemoval = TRUE;

    try {
      $this->manager->revokeBenefit($entitlement);
      self::fail('Expected Group membership removal to fail.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('Simulated membership removal failure.', $exception->getMessage());
    }

    self::assertSame(1, (int) $this->ledger(6)['active']);
    self::assertArrayHasKey(7, $this->members);

    $this->failRemoval = FALSE;
    $this->manager->revokeBenefit($entitlement);
    self::assertSame(0, (int) $this->ledger(6)['active']);
    self::assertArrayNotHasKey(7, $this->members);
  }

  /**
   * Returns the one configured Class target.
   */
  private function targets(): array {
    return [['class_id' => $this->class->id()]];
  }

  /**
   * Loads a ledger row for an entitlement.
   */
  private function ledger(int $entitlement_id): array {
    $row = $this->database
      ->select('commerce_lms_entitlement_membership', 'm')
      ->fields('m')
      ->condition('eid', $entitlement_id)
      ->execute()
      ->fetchAssoc();
    self::assertIsArray($row);
    return $row;
  }

}
