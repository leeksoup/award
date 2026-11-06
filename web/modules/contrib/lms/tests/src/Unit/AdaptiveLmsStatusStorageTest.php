<?php

declare(strict_types=1);

namespace Drupal\Tests\lms\Unit;

use Drupal\lms\Entity\Handlers\AdaptiveLmsStatusStorage;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group as AttributesGroup;

/**
 * Tests the session-storage kill switch on the progress storage handler.
 */
#[AttributesGroup('lms')]
final class AdaptiveLmsStatusStorageTest extends UnitTestCase {

  /**
   * Reads the protected static flag behind withoutSessionStorage().
   */
  private function isDisabled(): bool {
    return (bool) (new \ReflectionProperty(AdaptiveLmsStatusStorage::class, 'noSessionStorage'))
      ->getValue();
  }

  /**
   * The flag is off by default, on inside the callable, and off again after.
   */
  public function testFlagIsScopedToTheCallable(): void {
    self::assertFalse($this->isDisabled());

    $inside = AdaptiveLmsStatusStorage::withoutSessionStorage(
      fn(): bool => $this->isDisabled(),
    );

    self::assertTrue($inside);
    self::assertFalse($this->isDisabled());
  }

  /**
   * The callable's return value is passed through untouched.
   */
  public function testReturnValueIsPassedThrough(): void {
    self::assertSame(
      'granted',
      AdaptiveLmsStatusStorage::withoutSessionStorage(static fn(): string => 'granted'),
    );
  }

  /**
   * A failed operation must not leave session storage switched off.
   */
  public function testFlagIsRestoredWhenTheCallableThrows(): void {
    try {
      AdaptiveLmsStatusStorage::withoutSessionStorage(static function (): void {
        throw new \RuntimeException('enrolment blew up');
      });
      self::fail('The exception should have propagated.');
    }
    catch (\RuntimeException $e) {
      self::assertSame('enrolment blew up', $e->getMessage());
    }

    self::assertFalse($this->isDisabled());
  }

  /**
   * Nesting restores the outer scope's value rather than switching back on.
   */
  public function testNestedCallsRestoreTheOuterScope(): void {
    $after_inner = AdaptiveLmsStatusStorage::withoutSessionStorage(function (): bool {
      AdaptiveLmsStatusStorage::withoutSessionStorage(static function (): void {});
      return $this->isDisabled();
    });

    self::assertTrue($after_inner, 'The inner call must not re-enable session storage.');
    self::assertFalse($this->isDisabled());
  }

}
