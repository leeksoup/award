<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Kernel;

use Drupal\commerce_lms_entitlements\Controller\EntitlementController;
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
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
      ],
      'primary key' => ['eid'],
    ]);
    $this->controller = new EntitlementController($database);
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

}
