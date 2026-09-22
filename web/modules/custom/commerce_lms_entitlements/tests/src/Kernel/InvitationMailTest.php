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
    self::assertSame(
      'Your course access at Example Site is ready',
      (string) $message['subject'],
    );
    self::assertCount(4, $message['body']);
    self::assertStringContainsString('Example Site', (string) $message['body'][0]);
    self::assertStringContainsString('secure, one-time link', (string) $message['body'][1]);
    self::assertSame(
      'https://example.com/invitation/test',
      $message['body'][2],
    );
    self::assertStringContainsString('expires in 30 days', (string) $message['body'][3]);
  }

}
