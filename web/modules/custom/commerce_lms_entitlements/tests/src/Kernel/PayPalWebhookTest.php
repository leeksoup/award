<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Kernel;

use Drupal\commerce_lms_entitlements\Controller\PayPalWebhookController;
use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\EntitlementMembershipManager;
use Drupal\commerce_lms_entitlements\Mailer\EntitlementMailerInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/** Tests webhook admission, duplicate identity, and durable event queuing. */
final class PayPalWebhookTest extends KernelTestBase {

  protected static $modules = ['system'];

  private Connection $database;
  private EntitlementManager $manager;
  private PayPalWebhookController $controller;
  private QueueInterface $queue;
  private QueueInterface $databaseQueue;
  private PaymentGatewayInterface $gateway;
  private object $sdk;
  private bool $allowVerification = TRUE;
  private int $registeredRequests = 0;
  private string $gatewayPlugin = 'paypal_checkout_subscriptions';

  protected function setUp(): void {
    parent::setUp();
    $this->database = $this->container->get('database');
    require_once dirname(__DIR__, 3) . '/commerce_lms_entitlements.install';
    $this->database->schema()->createTable('commerce_lms_entitlement_event', commerce_lms_entitlements_schema()['commerce_lms_entitlement_event']);
    $this->databaseQueue = $this->container->get('queue')->get('commerce_lms_entitlements_webhook');
    $this->databaseQueue->createQueue();
    $this->queue = $this->databaseQueue;
    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory->method('get')->willReturnCallback(fn (): QueueInterface => $this->queue);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_000);
    $this->sdk = new class {
      public int $calls = 0;
      public string $verification = 'SUCCESS';
      public bool $fail = FALSE;
      public array $parameters = [];

      public function get(array $config): object {
        return $this;
      }

      public function verifyWebhookSignature(array $parameters): Response {
        $this->calls++;
        $this->parameters = $parameters;
        if ($this->fail) {
          throw new \RuntimeException('Simulated PayPal outage.');
        }
        return new Response(200, [], json_encode(['verification_status' => $this->verification], JSON_THROW_ON_ERROR));
      }
    };
    $logger = $this->createMock(LoggerInterface::class);
    $this->manager = new EntitlementManager(
      $this->database,
      $entity_type_manager,
      new EntitlementMembershipManager($this->database, $entity_type_manager, $time),
      $this->container->get('config.factory'),
      $queue_factory,
      $time,
      $logger,
      $this->sdk,
      $this->createMock(EntitlementMailerInterface::class),
      $this->createMock(UrlGeneratorInterface::class),
    );
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturnCallback(function ($name, $threshold, $window, $identifier): bool {
      self::assertSame(PayPalWebhookController::FLOOD_EVENT, $name);
      self::assertSame(PayPalWebhookController::VERIFICATION_LIMIT, $threshold);
      self::assertSame(PayPalWebhookController::VERIFICATION_WINDOW, $window);
      self::assertSame(hash('sha256', '192.0.2.1'), $identifier);
      return $this->allowVerification;
    });
    $flood->method('register')->willReturnCallback(function (): void {
      $this->registeredRequests++;
    });
    $this->gateway = $this->createMock(PaymentGatewayInterface::class);
    $this->gateway->method('id')->willReturn('sandbox');
    $this->gateway->method('getPluginId')->willReturnCallback(fn (): string => $this->gatewayPlugin);
    $this->gateway->method('getPluginConfiguration')->willReturn(['webhook_id' => 'WH-TEST']);
    $this->controller = new PayPalWebhookController($this->manager, $this->sdk, $flood, $logger);
  }

  /** Successful and duplicate deliveries queue the verified event only once. */
  public function testSuccessAndDuplicateDelivery(): void {
    $first = $this->controller->receive($this->request(), $this->gateway);
    self::assertSame(200, $first->getStatusCode());
    self::assertSame(['received' => TRUE, 'duplicate' => FALSE], json_decode($first->getContent(), TRUE));
    self::assertSame('WH-TEST', $this->sdk->parameters['webhook_id']);
    self::assertSame($this->event(), $this->sdk->parameters['webhook_event']);
    $second = $this->controller->receive($this->request(), $this->gateway);
    self::assertSame(['received' => TRUE, 'duplicate' => TRUE], json_decode($second->getContent(), TRUE));
    self::assertSame(2, $this->sdk->calls);
    self::assertSame(2, $this->registeredRequests);
    self::assertSame(1, (int) $this->databaseQueue->numberOfItems());
    self::assertNotNull($this->manager->event('WH-EVENT'));
  }

  /** Invalid JSON and malformed event shapes never reach verification. */
  public function testMalformedPayloads(): void {
    foreach (['{', 'null', '"scalar"', '[]', '{}', '{"id":[],"event_type":"x","resource":{}}'] as $body) {
      self::assertSame(400, $this->controller->receive($this->request($body), $this->gateway)->getStatusCode());
    }
    self::assertSame(0, $this->sdk->calls);
    self::assertSame(0, (int) $this->databaseQueue->numberOfItems());
  }

  /** Both declared and undeclared oversized bodies are rejected. */
  public function testOversizedPayloads(): void {
    $request = $this->request();
    $request->headers->set('Content-Length', (string) (PayPalWebhookController::MAX_BODY_BYTES + 1));
    self::assertSame(413, $this->controller->receive($request, $this->gateway)->getStatusCode());
    $request = $this->request(str_repeat(' ', PayPalWebhookController::MAX_BODY_BYTES + 1));
    $request->headers->set('Content-Length', '1');
    self::assertSame(413, $this->controller->receive($request, $this->gateway)->getStatusCode());
    self::assertSame(0, $this->sdk->calls);
    self::assertSame(0, $this->registeredRequests);
  }

  /** A valid body at the configured byte limit can still be verified. */
  public function testPayloadAtSizeLimit(): void {
    $body = str_pad(json_encode($this->event(), JSON_THROW_ON_ERROR), PayPalWebhookController::MAX_BODY_BYTES, ' ');
    self::assertSame(200, $this->controller->receive($this->request($body), $this->gateway)->getStatusCode());
    self::assertSame(1, (int) $this->databaseQueue->numberOfItems());
  }

  /** Missing signature headers are rejected before calling PayPal. */
  public function testMissingSignatureHeader(): void {
    $request = $this->request();
    $request->headers->remove('paypal-transmission-sig');
    self::assertSame(400, $this->controller->receive($request, $this->gateway)->getStatusCode());
    self::assertSame(0, $this->sdk->calls);
  }

  /** A failed signature cannot persist an event or queue work. */
  public function testInvalidSignature(): void {
    $this->sdk->verification = 'FAILURE';
    self::assertSame(400, $this->controller->receive($this->request(), $this->gateway)->getStatusCode());
    self::assertNull($this->manager->event('WH-EVENT'));
    self::assertSame(0, (int) $this->databaseQueue->numberOfItems());
    self::assertSame(1, $this->registeredRequests);
  }

  /** Only the subscriptions gateway may accept subscription webhooks. */
  public function testUnknownGateway(): void {
    $this->gatewayPlugin = 'paypal_checkout';
    self::assertSame(404, $this->controller->receive($this->request(), $this->gateway)->getStatusCode());
    self::assertSame(0, $this->sdk->calls);
  }

  /** Admission limits prevent verification and return a retry interval. */
  public function testRateLimitedRequest(): void {
    $this->allowVerification = FALSE;
    $response = $this->controller->receive($this->request(), $this->gateway);
    self::assertSame(429, $response->getStatusCode());
    self::assertSame('60', $response->headers->get('Retry-After'));
    self::assertSame(0, $this->sdk->calls);
    self::assertSame(0, $this->registeredRequests);
    self::assertSame(0, (int) $this->databaseQueue->numberOfItems());
  }

  /** A verified non-subscription event is acknowledged and ignored. */
  public function testIgnoredEvent(): void {
    $event = $this->event();
    $event['event_type'] = 'BILLING.PLAN.UPDATED';
    $response = $this->controller->receive($this->request(json_encode($event, JSON_THROW_ON_ERROR)), $this->gateway);
    self::assertSame(200, $response->getStatusCode());
    self::assertSame(['received' => TRUE, 'ignored' => TRUE], json_decode($response->getContent(), TRUE));
    self::assertNull($this->manager->event('WH-EVENT'));
  }

  /** Verification service failures leave the delivery retryable. */
  public function testVerificationFailureReturnsServerError(): void {
    $this->sdk->fail = TRUE;
    self::assertSame(503, $this->controller->receive($this->request(), $this->gateway)->getStatusCode());
    self::assertNull($this->manager->event('WH-EVENT'));
  }

  /** A missing event table must not be acknowledged as a duplicate. */
  public function testStorageFailureReturnsServerError(): void {
    $this->database->schema()->dropTable('commerce_lms_entitlement_event');
    $response = $this->controller->receive($this->request(), $this->gateway);
    self::assertSame(503, $response->getStatusCode());
    self::assertSame(['error' => 'Webhook temporarily unavailable'], json_decode($response->getContent(), TRUE));
    self::assertSame(0, (int) $this->databaseQueue->numberOfItems());
  }

  /** Queue failure rolls back both the event and database queue item. */
  public function testQueueFailureRollsBackAndCanRetry(): void {
    $this->database->schema()->dropTable('queue');
    $this->queue = $this->createMock(QueueInterface::class);
    $this->queue->method('createQueue')->willReturnCallback(function (): void {
      $this->databaseQueue->createQueue();
    });
    $this->queue->method('createItem')->willReturnCallback(function (array $data): void {
      $this->databaseQueue->createItem($data);
      throw new \RuntimeException('Simulated queue failure.');
    });
    self::assertSame(503, $this->controller->receive($this->request(), $this->gateway)->getStatusCode());
    self::assertNull($this->manager->event('WH-EVENT'));
    self::assertSame(0, (int) $this->databaseQueue->numberOfItems());
    $this->queue = $this->databaseQueue;
    self::assertSame(200, $this->controller->receive($this->request(), $this->gateway)->getStatusCode());
    self::assertSame(1, (int) $this->databaseQueue->numberOfItems());
  }

  /** Queue backends may signal failure by returning FALSE. */
  public function testFalseQueueResultRollsBack(): void {
    $this->queue = $this->createMock(QueueInterface::class);
    $this->queue->method('createItem')->willReturn(FALSE);
    self::assertSame(503, $this->controller->receive($this->request(), $this->gateway)->getStatusCode());
    self::assertNull($this->manager->event('WH-EVENT'));
  }

  /** A primary-key collision with a different identity is an error. */
  public function testConflictingEventIdentityIsNotDuplicate(): void {
    $this->manager->queueEvent($this->event(), 'another-gateway');
    self::assertSame(503, $this->controller->receive($this->request(), $this->gateway)->getStatusCode());
    self::assertSame(1, (int) $this->databaseQueue->numberOfItems());
  }

  /** Changing the parent subscription cannot reuse a stored event identity. */
  public function testConflictingSubscriptionIsNotDuplicate(): void {
    $this->manager->queueEvent($this->event(), 'sandbox');
    $event = $this->event();
    $event['resource']['id'] = 'I-OTHER';
    self::assertSame(503, $this->controller->receive($this->request(json_encode($event, JSON_THROW_ON_ERROR)), $this->gateway)->getStatusCode());
    self::assertSame(1, (int) $this->databaseQueue->numberOfItems());
  }

  /** Changing the event type cannot reuse a stored event identity. */
  public function testConflictingEventTypeIsNotDuplicate(): void {
    $this->manager->queueEvent($this->event(), 'sandbox');
    $event = $this->event();
    $event['event_type'] = 'BILLING.SUBSCRIPTION.CANCELLED';
    self::assertSame(503, $this->controller->receive($this->request(json_encode($event, JSON_THROW_ON_ERROR)), $this->gateway)->getStatusCode());
    self::assertSame(1, (int) $this->databaseQueue->numberOfItems());
  }

  /** A non-primary-key constraint failure must propagate for retry. */
  public function testUnrelatedIntegrityFailurePropagates(): void {
    $this->database->schema()->dropTable('commerce_lms_entitlement_event');
    $schema = commerce_lms_entitlements_schema()['commerce_lms_entitlement_event'];
    $schema['unique keys']['test_event_type'] = ['event_type'];
    $this->database->schema()->createTable('commerce_lms_entitlement_event', $schema);
    $this->manager->queueEvent($this->event(), 'sandbox');
    $other = $this->event();
    $other['id'] = 'WH-OTHER';
    $this->expectException(IntegrityConstraintViolationException::class);
    $this->manager->queueEvent($other, 'sandbox');
  }

  /** Legacy rows containing a sale ID still deduplicate by subscription. */
  public function testLegacyPaymentEventDuplicate(): void {
    $event = $this->event();
    $event['event_type'] = 'PAYMENT.SALE.COMPLETED';
    $event['resource'] = ['id' => 'SALE-1', 'billing_agreement_id' => 'I-SUB'];
    $this->manager->queueEvent($event, 'sandbox');
    $this->database->update('commerce_lms_entitlement_event')->fields(['paypal_subscription_id' => 'SALE-1'])->condition('event_id', $event['id'])->execute();
    self::assertFalse($this->manager->queueEvent($event, 'sandbox'));
    self::assertSame(1, (int) $this->databaseQueue->numberOfItems());
  }

  /** Missing event IDs never enter storage. */
  public function testMissingEventId(): void {
    $event = $this->event();
    unset($event['id']);
    $this->expectException(\InvalidArgumentException::class);
    $this->manager->queueEvent($event, 'sandbox');
  }

  private function event(): array {
    return ['id' => 'WH-EVENT', 'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => ['id' => 'I-SUB']];
  }

  private function request(?string $body = NULL): Request {
    return Request::create('/commerce-lms-entitlements/paypal/webhook/sandbox', 'POST', [], [], [], [
      'REMOTE_ADDR' => '192.0.2.1',
      'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
      'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/test-cert',
      'HTTP_PAYPAL_TRANSMISSION_ID' => 'transmission',
      'HTTP_PAYPAL_TRANSMISSION_SIG' => 'signature',
      'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-11-11T00:00:00Z',
    ], $body ?? json_encode($this->event(), JSON_THROW_ON_ERROR));
  }

}
