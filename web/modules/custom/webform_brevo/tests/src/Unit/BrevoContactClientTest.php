<?php

declare(strict_types=1);

namespace Drupal\Tests\webform_brevo\Unit;

use Brevo\Client\Configuration;
use Brevo\Client\Model\CreateContact;
use Drupal\webform_brevo\BrevoContactClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests the real SDK's result and exception formats without network access.
 *
 * @group webform_brevo
 */
final class BrevoContactClientTest extends TestCase {

  public function testCreatedContactResponse(): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(201, ['X-Test' => 'created'], '{"id":42}')]));
    $stack->push(Middleware::history($history));
    $client = new BrevoContactClient(new Client(['handler' => $stack]));
    $contact = new CreateContact(['email' => 'learner@example.com', 'listIds' => [7], 'updateEnabled' => TRUE]);
    $result = $client->upsert('test-key', $contact);
    self::assertSame(201, $result->getStatusCode());
    self::assertSame(['id' => 42], json_decode((string) $result->getBody(), TRUE));
    self::assertSame('created', $result->getHeaderLine('X-Test'));
    self::assertSame('POST', $history[0]['request']->getMethod());
    self::assertSame('https://api.brevo.com/v3/contacts', (string) $history[0]['request']->getUri());
    self::assertSame('test-key', $history[0]['request']->getHeaderLine('api-key'));
    self::assertSame([7], json_decode((string) $history[0]['request']->getBody(), TRUE)['listIds']);
  }

  public function testUpdatedContactWithEmptyResponse(): void {
    $client = new BrevoContactClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(204)]))]));
    $response = $client->upsert('test-key', new CreateContact(['email' => 'learner@example.com']));
    self::assertSame(204, $response->getStatusCode());
    self::assertSame('', (string) $response->getBody());
  }

  /**
   * @dataProvider errorResponseProvider
   */
  public function testSdkApiFailuresBecomeResponses(int $status, bool $http_errors): void {
    $body = '{"message":"API Key is not enabled","code":"unauthorized"}';
    $client = new BrevoContactClient(new Client([
      'handler' => HandlerStack::create(new MockHandler([new Response($status, [], $body)])),
      'http_errors' => $http_errors,
    ]));
    $response = $client->upsert('test-key', new CreateContact(['email' => 'learner@example.com']));
    self::assertSame($status, $response->getStatusCode());
    self::assertSame(json_decode($body, TRUE), json_decode((string) $response->getBody(), TRUE));
  }

  public static function errorResponseProvider(): array {
    return ['unauthorized' => [401, TRUE], 'bad contact' => [400, TRUE], 'provider outage' => [503, TRUE], 'stream body' => [401, FALSE]];
  }

  public function testConnectionFailureBecomesUnavailableResponse(): void {
    $handler = new MockHandler([new ConnectException('Simulated connection failure.', new Request('POST', 'https://api.brevo.com/v3/contacts'))]);
    $client = new BrevoContactClient(new Client(['handler' => HandlerStack::create($handler)]));
    $response = $client->upsert('test-key', new CreateContact(['email' => 'learner@example.com']));
    self::assertSame(503, $response->getStatusCode());
    self::assertSame('connection_failed', json_decode((string) $response->getBody(), TRUE)['code']);
  }

  public function testEmptyKeyMakesNoRequest(): void {
    $client = new BrevoContactClient(new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
    self::assertSame(503, $client->upsert('', new CreateContact(['email' => 'learner@example.com']))->getStatusCode());
  }

  public function testKeysAreIsolatedBetweenHandlers(): void {
    $configuration = Configuration::getDefaultConfiguration();
    $original = $configuration->getApiKey('api-key');
    $configuration->setApiKey('api-key', 'unrelated-key');
    try {
      $history = [];
      $stack = HandlerStack::create(new MockHandler([new Response(204), new Response(204)]));
      $stack->push(Middleware::history($history));
      $client = new BrevoContactClient(new Client(['handler' => $stack]));
      $contact = new CreateContact(['email' => 'learner@example.com']);
      $client->upsert('first-key', $contact);
      $client->upsert('second-key', $contact);
      self::assertSame('first-key', $history[0]['request']->getHeaderLine('api-key'));
      self::assertSame('second-key', $history[1]['request']->getHeaderLine('api-key'));
      self::assertSame('unrelated-key', $configuration->getApiKey('api-key'));
    }
    finally {
      $configuration->setApiKey('api-key', $original);
    }
  }

}
