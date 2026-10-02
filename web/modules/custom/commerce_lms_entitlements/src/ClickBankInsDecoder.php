<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

/** Decodes the documented ClickBank INS version 8 AES-CBC envelope. */
final class ClickBankInsDecoder {
  public static function decode(string $body, string $secret, string $vendor): array {
    if ($secret === '' || $vendor === '' || strlen($body) > 65536) {
      throw new \DomainException('ClickBank INS is unavailable or oversized.');
    }
    $envelope = json_decode($body, TRUE);
    $ciphertext = is_array($envelope) ? base64_decode((string) ($envelope['notification'] ?? ''), TRUE) : FALSE;
    $iv = is_array($envelope) ? base64_decode((string) ($envelope['iv'] ?? ''), TRUE) : FALSE;
    if ($ciphertext === FALSE || $ciphertext === '' || $iv === FALSE || strlen($iv) !== 16) {
      throw new \DomainException('Invalid ClickBank INS envelope.');
    }
    // ClickBank specifies the first 32 ASCII characters of the SHA-1 hex
    // digest as the AES key, not 32 decoded hex bytes.
    $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', substr(sha1($secret), 0, 32), OPENSSL_RAW_DATA, $iv);
    $event = is_string($plaintext) ? json_decode(trim($plaintext, "\0..\32"), TRUE) : NULL;
    if (!is_array($event)
      || (float) ($event['version'] ?? 0) !== 8.0
      || (string) ($event['vendor'] ?? '') !== $vendor
      || (string) ($event['role'] ?? '') !== 'VENDOR'
      || (($event['transactionType'] ?? '') !== 'TEST' && !preg_match('/^[A-Za-z0-9-]{8,64}$/', (string) ($event['receipt'] ?? '')))
      || !in_array((string) ($event['transactionType'] ?? ''), ['SALE', 'RFND', 'CGBK', 'INSF', 'TEST', 'TEST_SALE', 'TEST_RFND'], TRUE)) {
      throw new \DomainException('Invalid or unsupported ClickBank INS notification.');
    }
    return $event;
  }
}
