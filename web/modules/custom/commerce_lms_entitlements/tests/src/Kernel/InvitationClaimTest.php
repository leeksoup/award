<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Kernel;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\EntitlementMembershipManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests atomic invitation claiming invariants.
 */
#[Group('commerce_lms_entitlements')]
final class InvitationClaimTest extends KernelTestBase {

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
  private EntitlementManager $manager;

  /**
   * Fixed request time for invitation expiry checks.
   */
  private const REQUEST_TIME = 1_700_000_000;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->container->get('database');
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
    $this->database->schema()->createTable('commerce_lms_entitlement', [
      'fields' => [
        'eid' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'invitation_id' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
      ],
      'primary key' => ['eid'],
    ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
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
      $this->createMock(\Drupal\Core\Mail\MailManagerInterface::class),
      $this->createMock(\Drupal\Core\Language\LanguageManagerInterface::class),
      $this->createMock(\Drupal\Core\Routing\UrlGeneratorInterface::class),
    );
  }

  /**
   * Exactly one claimant can consume a one-time invitation.
   */
  public function testOnlyOneClaimWins(): void {
    $this->insertInvitation('claim-token', 'learner@example.com');

    self::assertTrue($this->manager->claimInvitation(
      'claim-token',
      7,
      'Learner@example.com',
    ));
    self::assertFalse($this->manager->claimInvitation(
      'claim-token',
      8,
      'learner@example.com',
    ));
    self::assertSame(7, $this->claimedUserId('claim-token'));
  }

  /**
   * A forwarded token cannot be claimed by a different email account.
   */
  public function testMismatchedEmailCannotClaim(): void {
    $this->insertInvitation('forwarded-token', 'learner@example.com');

    self::assertFalse($this->manager->claimInvitation(
      'forwarded-token',
      8,
      'other@example.com',
    ));
    self::assertNull($this->claimedUserId('forwarded-token'));
  }

  /**
   * An expired invitation cannot be claimed.
   */
  public function testExpiredInvitationCannotBeClaimed(): void {
    $this->insertInvitation(
      'expired-token',
      'learner@example.com',
      self::REQUEST_TIME,
    );

    self::assertFalse($this->manager->claimInvitation(
      'expired-token',
      7,
      'learner@example.com',
    ));
    self::assertNull($this->claimedUserId('expired-token'));
  }

  /**
   * Inserts a test invitation.
   */
  private function insertInvitation(
    string $token,
    string $email,
    int $expires = self::REQUEST_TIME + 3600,
  ): void {
    $this->database->insert('commerce_lms_entitlement_invitation')
      ->fields([
        'id' => hash('sha256', 'id:' . $token),
        'email' => $email,
        'token_hash' => hash('sha256', $token),
        'created' => self::REQUEST_TIME - 60,
        'expires' => $expires,
      ])
      ->execute();
  }

  /**
   * Returns the invitation's claimant UID, if claimed.
   */
  private function claimedUserId(string $token): ?int {
    $invitation = $this->database
      ->select('commerce_lms_entitlement_invitation', 'i')
      ->fields('i', ['claimed_uid'])
      ->condition('token_hash', hash('sha256', $token))
      ->execute()
      ->fetchAssoc();
    self::assertIsArray($invitation);
    return $invitation['claimed_uid'] === NULL
      ? NULL
      : (int) $invitation['claimed_uid'];
  }

}
