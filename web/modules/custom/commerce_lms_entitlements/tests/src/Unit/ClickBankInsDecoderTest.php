<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Unit;

use Drupal\commerce_lms_entitlements\ClickBankInsDecoder;
use PHPUnit\Framework\TestCase;

/** @coversDefaultClass \Drupal\commerce_lms_entitlements\ClickBankInsDecoder */
final class ClickBankInsDecoderTest extends TestCase {
  public function testVerifiedVendorSale(): void {
    $event = [
      'version' => 8,
      'receipt' => 'TEST0000',
      'transactionType' => 'SALE',
      'vendor' => 'example',
      'role' => 'VENDOR',
      'vendorVariables' => ['v1' => 'opaque'],
    ];
    $secret = 'test-secret';
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt(json_encode($event, JSON_THROW_ON_ERROR), 'AES-256-CBC', substr(sha1($secret), 0, 32), OPENSSL_RAW_DATA, $iv);
    $envelope = json_encode(['notification' => base64_encode($ciphertext), 'iv' => base64_encode($iv)], JSON_THROW_ON_ERROR);
    self::assertSame($event, ClickBankInsDecoder::decode($envelope, $secret, 'example'));
    $this->expectException(\DomainException::class);
    ClickBankInsDecoder::decode($envelope, $secret, 'other');
  }

  public function testInvalidEnvelopeIsRejected(): void {
    $this->expectException(\DomainException::class);
    ClickBankInsDecoder::decode('{"notification":"!!!","iv":"!!!"}', 'secret', 'vendor');
  }

  public function testTestPingDoesNotRequireReceipt(): void {
    $event = ['version' => 8, 'transactionType' => 'TEST', 'vendor' => 'example', 'role' => 'VENDOR'];
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt(json_encode($event, JSON_THROW_ON_ERROR), 'AES-256-CBC', substr(sha1('secret'), 0, 32), OPENSSL_RAW_DATA, $iv);
    $envelope = json_encode(['notification' => base64_encode($ciphertext), 'iv' => base64_encode($iv)], JSON_THROW_ON_ERROR);
    self::assertSame($event, ClickBankInsDecoder::decode($envelope, 'secret', 'example'));
  }
}
