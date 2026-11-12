<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Controller;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Verifies bounded, rate-limited webhook requests before queuing work. */
final class PayPalWebhookController extends ControllerBase {

  public const MAX_BODY_BYTES = 1_048_576;
  public const VERIFICATION_LIMIT = 120;
  public const VERIFICATION_WINDOW = 60;
  public const FLOOD_EVENT = 'commerce_lms_entitlements.paypal_webhook';

  public function __construct(
    private EntitlementManager $manager,
    private object $sdkFactory,
    private FloodInterface $flood,
    private LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_lms_entitlements.manager'),
      $container->get('commerce_paypal_subscriptions.checkout_sdk_factory'),
      $container->get('flood'),
      $container->get('logger.channel.commerce_lms_entitlements'),
    );
  }

  public function receive(Request $request, PaymentGatewayInterface $commerce_payment_gateway): JsonResponse {
    $event = [];
    try {
      if ($commerce_payment_gateway->getPluginId() !== 'paypal_checkout_subscriptions') {
        return new JsonResponse(['error' => 'Unknown gateway'], 404);
      }
      if ((int) $request->headers->get('Content-Length', '0') > self::MAX_BODY_BYTES) {
        return new JsonResponse(['error' => 'Webhook body too large'], 413);
      }
      // Read one byte beyond the limit to detect oversized bodies even when
      // Content-Length is missing or inaccurate. Decode only bounded input.
      $body = stream_get_contents($request->getContent(TRUE), self::MAX_BODY_BYTES + 1);
      if ($body === FALSE) {
        throw new \RuntimeException('The webhook body could not be read.');
      }
      if (strlen($body) > self::MAX_BODY_BYTES) {
        return new JsonResponse(['error' => 'Webhook body too large'], 413);
      }
      try {
        $event = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        return new JsonResponse(['error' => 'Invalid JSON'], 400);
      }
      if (!is_array($event) || !is_string($event['id'] ?? NULL)
        || $event['id'] === '' || strlen($event['id']) > 128
        || !is_string($event['event_type'] ?? NULL) || $event['event_type'] === ''
        || strlen($event['event_type']) > 128 || !is_array($event['resource'] ?? NULL)) {
        return new JsonResponse(['error' => 'Invalid webhook event'], 400);
      }
      $parameters = [];
      foreach ([
        'auth_algo' => 'paypal-auth-algo',
        'cert_url' => 'paypal-cert-url',
        'transmission_id' => 'paypal-transmission-id',
        'transmission_sig' => 'paypal-transmission-sig',
        'transmission_time' => 'paypal-transmission-time',
      ] as $parameter => $header) {
        $parameters[$parameter] = $request->headers->get($header, '');
        if (trim($parameters[$parameter]) === '') {
          return new JsonResponse(['error' => 'Missing signature headers'], 400);
        }
      }
      $identifier = hash('sha256', $request->getClientIp() ?? 'unknown');
      if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::VERIFICATION_LIMIT, self::VERIFICATION_WINDOW, $identifier)) {
        return new JsonResponse(['error' => 'Verification rate exceeded'], 429, ['Retry-After' => (string) self::VERIFICATION_WINDOW]);
      }
      $this->flood->register(self::FLOOD_EVENT, self::VERIFICATION_WINDOW, $identifier);
      $config = $commerce_payment_gateway->getPluginConfiguration();
      if (empty($config['webhook_id'])) {
        throw new \RuntimeException('The gateway webhook ID is missing.');
      }
      $parameters['webhook_id'] = $config['webhook_id'];
      $parameters['webhook_event'] = $event;
      $response = $this->sdkFactory->get($config)->verifyWebhookSignature($parameters);
      $verified = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      if (($verified['verification_status'] ?? '') !== 'SUCCESS') {
        return new JsonResponse(['error' => 'Invalid signature'], 400);
      }
      if (!$this->manager->isSubscriptionEvent($event)) {
        return new JsonResponse(['received' => TRUE, 'ignored' => TRUE]);
      }
      return new JsonResponse([
        'received' => TRUE,
        'duplicate' => !$this->manager->queueEvent($event, (string) $commerce_payment_gateway->id()),
      ]);
    }
    catch (\Throwable $exception) {
      // Database messages can contain bound event payloads. Log identifiers
      // and the exception class without copying customer data into logs.
      $this->logger->error('PayPal webhook processing failed for gateway @gateway, event @event (@exception).', [
        '@gateway' => $commerce_payment_gateway->id(),
        '@event' => is_array($event) && is_string($event['id'] ?? NULL) ? $event['id'] : '(unavailable)',
        '@exception' => $exception::class,
      ]);
      return new JsonResponse(['error' => 'Webhook temporarily unavailable'], 503, ['Retry-After' => '60']);
    }
  }

}
