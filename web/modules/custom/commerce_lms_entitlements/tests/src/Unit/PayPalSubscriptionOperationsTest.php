<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Unit;

use Drupal\commerce_lms_entitlements\PayPalSubscriptionOperations;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/** Tests PayPal plan creation requests. */
final class PayPalSubscriptionOperationsTest extends UnitTestCase {

  public function testCreatePlanUsesGatewayModeAndIdempotencyKey(): void {
    $history = [];
    $mock = new MockHandler([
      new Response(200, [], json_encode(['access_token' => 'token'], JSON_THROW_ON_ERROR)),
      new Response(201, [], json_encode(['id' => 'P-CREATED', 'status' => 'ACTIVE'], JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $operations = new PayPalSubscriptionOperations(new Client(['handler' => $stack]));
    $gateway = new class {
      public function getPluginConfiguration(): array {
        return [
          'mode' => 'live',
          'client_id' => 'client',
          'client_secret' => 'secret',
        ];
      }
    };
    $payload = [
      'product_id' => 'PROD-TEST',
      'name' => 'Test plan',
      'billing_cycles' => [],
    ];

    $result = $operations->createPlan($gateway, $payload, 'cle-request-id');

    self::assertSame('P-CREATED', $result['id']);
    self::assertCount(2, $history);
    self::assertSame('https://api-m.paypal.com/v1/billing/plans', (string) $history[1]['request']->getUri());
    self::assertSame('cle-request-id', $history[1]['request']->getHeaderLine('PayPal-Request-Id'));
    self::assertSame($payload, json_decode((string) $history[1]['request']->getBody(), TRUE, 512, JSON_THROW_ON_ERROR));
  }

}
