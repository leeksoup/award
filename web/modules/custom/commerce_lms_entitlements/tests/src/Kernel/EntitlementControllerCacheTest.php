<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Kernel;

use Drupal\commerce_lms_entitlements\Controller\EntitlementController;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests cacheability of personalized entitlement output.
 */
#[Group('commerce_lms_entitlements')]
final class EntitlementControllerCacheTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The controller under test.
   */
  private EntitlementController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    /** @var \Drupal\Core\Database\Connection $database */
    $database = $this->container->get('database');
    $database->schema()->createTable('commerce_lms_entitlement', [
      'fields' => [
        'eid' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'purchaser_uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'offer_id' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'purchase_type' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE],
        'status' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
        'learner_uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'access_through' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
      ],
      'primary key' => ['eid'],
    ]);
    $this->controller = EntitlementController::create($this->container);
  }

  /**
   * Purchaser-specific output cannot be reused for another account.
   */
  public function testPurchaserPageIsUncacheable(): void {
    $build = $this->controller->mine();

    self::assertSame(0, $build['#cache']['max-age']);
  }

  /**
   * Administrative output cannot retain stale custom-table data.
   */
  public function testAdministrativePageIsUncacheable(): void {
    $build = $this->controller->admin();

    self::assertSame(0, $build['#cache']['max-age']);
  }

  /**
   * Populated rows format database timestamps through the injected service.
   */
  public function testPaidThroughDateUsesFormatter(): void {
    $formatter = $this->createMock(DateFormatterInterface::class);
    $formatter->expects(self::once())
      ->method('format')
      ->with(1_700_000_000, 'short')
      ->willReturn('Formatted paid-through date');
    $this->container->set('date.formatter', $formatter);
    $controller = EntitlementController::create($this->container);

    $this->container->get('database')->insert('commerce_lms_entitlement')->fields([
      'purchaser_uid' => 7,
      'offer_id' => 'monthly',
      'purchase_type' => 'recurring',
      'status' => 'active',
      'learner_uid' => 8,
      'access_through' => 1_700_000_000,
      'created' => 1_700_000_000,
      'changed' => 1_700_000_000,
    ])->execute();

    $build = $controller->admin();
    self::assertSame('Formatted paid-through date', $build['table']['#rows'][0][6]);
    self::assertSame(0, $build['#cache']['max-age']);
  }

}
