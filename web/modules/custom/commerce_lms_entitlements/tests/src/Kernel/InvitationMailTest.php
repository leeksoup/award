<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests invitation mail originator headers.
 */
#[Group('commerce_lms_entitlements')]
final class InvitationMailTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system']);
    $this->config('system.site')
      ->set('name', 'Example Site')
      ->set('mail', 'webmaster@example.com')
      ->save();

    require_once DRUPAL_ROOT . '/modules/custom/commerce_lms_entitlements/commerce_lms_entitlements.module';
  }

  /**
   * Mail has the complete aligned originator headers expected by core.
   */
  public function testInvitationOriginatorHeaders(): void {
    $message = [
      'headers' => [],
      'body' => [],
    ];

    commerce_lms_entitlements_mail('invitation', $message, [
      'url' => 'https://example.com/invitation/test',
    ]);

    self::assertSame('webmaster@example.com', $message['from']);
    self::assertSame(
      'Example Site <webmaster@example.com>',
      $message['headers']['From'],
    );
    self::assertSame(
      'webmaster@example.com',
      $message['headers']['Sender'],
    );
    self::assertSame(
      'webmaster@example.com',
      $message['headers']['Return-Path'],
    );
  }

}
