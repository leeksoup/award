<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Unit;

use Drupal\commerce_lms_entitlements\VipGroupAccessChecker;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests VIP Hub Course and Class membership access.
 */
#[Group('commerce_lms_entitlements')]
final class VipGroupAccessCheckerTest extends UnitTestCase {

  /**
   * Tests access through either configured group, or neither group.
   *
   * @dataProvider membershipProvider
   */
  public function testConfiguredGroupMembership(
    bool $course_member,
    bool $class_member,
    bool $expected,
  ): void {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn(7);

    $course = $this->groupMock($account, $course_member);
    $class = $this->groupMock($account, $class_member);
    $checker = $this->checker($account, [41 => $course, 42 => $class]);

    self::assertSame($expected, $checker->hasAccess(7));
  }

  /**
   * Provides Course and Class membership combinations.
   */
  public static function membershipProvider(): array {
    return [
      'course member' => [TRUE, FALSE, TRUE],
      'class member' => [FALSE, TRUE, TRUE],
      'both memberships' => [TRUE, TRUE, TRUE],
      'no membership' => [FALSE, FALSE, FALSE],
    ];
  }

  /**
   * Anonymous and missing users never receive access.
   */
  public function testInvalidAccountCannotAccess(): void {
    $checker = $this->checker(NULL, []);

    self::assertFalse($checker->hasAccess(0));
    self::assertFalse($checker->hasAccess(7));
  }

  /**
   * Builds a checker backed by mocked entity storage and VIP configuration.
   *
   * @param \Drupal\user\UserInterface|null $account
   *   The account returned for UID 7.
   * @param array<int, \Drupal\group\Entity\GroupInterface> $groups
   *   Groups keyed by group ID.
   */
  private function checker(?UserInterface $account, array $groups): VipGroupAccessChecker {
    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user_storage->method('load')->willReturnCallback(
      static fn (int|string $id): ?UserInterface => (int) $id === 7
        ? $account
        : NULL,
    );
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')->willReturnCallback(
      static fn (int|string $id): ?GroupInterface => $groups[(int) $id] ?? NULL,
    );

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(
      static fn (string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'user' => $user_storage,
        'group' => $group_storage,
        default => throw new \LogicException("Unexpected entity type: $entity_type_id"),
      },
    );

    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['hub_course_id', 41],
      ['hub_class_id', 42],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('commerce_lms_entitlements.vip')
      ->willReturn($config);

    return new VipGroupAccessChecker($entity_type_manager, $config_factory);
  }

  /**
   * Creates a Group mock with the requested membership result.
   */
  private function groupMock(UserInterface $account, bool $member): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getMember')
      ->with($account)
      ->willReturnCallback(
        static fn (UserInterface $loaded_account): object|false => $member
          ? (object) ['uid' => $loaded_account->id()]
          : FALSE,
      );
    return $group;
  }

}
