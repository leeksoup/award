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
      'billing_cycles' => [
        [
          'pricing_scheme' => [
            'fixed_price' => [
              'value' => '9.700000',
              'currency_code' => 'USD',
            ],
          ],
        ],
        [
          'pricing_scheme' => [
            'fixed_price' => [
              'value' => '197.000000',
              'currency_code' => 'USD',
            ],
          ],
        ],
      ],
      'payment_preferences' => [
        'setup_fee' => [
          'value' => '0.000000',
          'currency_code' => 'USD',
        ],
      ],
    ];

    $result = $operations->createPlan($gateway, $payload, 'cle-request-id');

    self::assertSame('P-CREATED', $result['id']);
    self::assertCount(2, $history);
    self::assertSame('https://api-m.paypal.com/v1/billing/plans', (string) $history[1]['request']->getUri());
    self::assertSame('cle-request-id', $history[1]['request']->getHeaderLine('PayPal-Request-Id'));
    $sent_payload = json_decode((string) $history[1]['request']->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame('9.7', $sent_payload['billing_cycles'][0]['pricing_scheme']['fixed_price']['value']);
    self::assertSame('197', $sent_payload['billing_cycles'][1]['pricing_scheme']['fixed_price']['value']);
    self::assertSame('0', $sent_payload['payment_preferences']['setup_fee']['value']);
  }

  public function testCreatePlanReportsPayPalValidationDetails(): void {
    $mock = new MockHandler([
      new Response(200, [], json_encode(['access_token' => 'token'], JSON_THROW_ON_ERROR)),
      new Response(400, [], json_encode([
        'name' => 'INVALID_REQUEST',
        'details' => [['field' => '/billing_cycles/0', 'issue' => 'Invalid cycle']],
      ], JSON_THROW_ON_ERROR)),
    ]);
    $operations = new PayPalSubscriptionOperations(new Client([
      'handler' => HandlerStack::create($mock),
    ]));
    $gateway = new class {
      public function getPluginConfiguration(): array {
        return [
          'mode' => 'test',
          'client_id' => 'client',
          'client_secret' => 'secret',
        ];
      }
    };

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('"field":"/billing_cycles/0"');
    $operations->createPlan($gateway, ['name' => 'Invalid'], 'cle-request-id');
  }

}
