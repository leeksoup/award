<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Controller;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Verifies PayPal subscription webhooks before handing them to the queue. */
final class PayPalWebhookController extends ControllerBase {
  public function __construct(private EntitlementManager $manager, private object $sdkFactory) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('commerce_lms_entitlements.manager'), $container->get('commerce_paypal_subscriptions.checkout_sdk_factory')); }
  public function receive(Request $request, object $commerce_payment_gateway): JsonResponse {
    try {
      if ($commerce_payment_gateway->getPluginId() !== 'paypal_checkout_subscriptions') { return new JsonResponse(['error' => 'Unknown gateway'], 404); }
      $event = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR); $headers = [];
      foreach ($request->headers->all() as $name => $values) { $headers[strtolower($name)] = $values[0] ?? ''; }
      $config = $commerce_payment_gateway->getPluginConfiguration();
      $parameters = ['auth_algo' => $headers['paypal-auth-algo'] ?? '', 'cert_url' => $headers['paypal-cert-url'] ?? '', 'transmission_id' => $headers['paypal-transmission-id'] ?? '', 'transmission_sig' => $headers['paypal-transmission-sig'] ?? '', 'transmission_time' => $headers['paypal-transmission-time'] ?? '', 'webhook_id' => $config['webhook_id'] ?? '', 'webhook_event' => $event];
      $response = $this->sdkFactory->get($config)->verifyWebhookSignature($parameters); $verified = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      if (($verified['verification_status'] ?? '') !== 'SUCCESS') { return new JsonResponse(['error' => 'Invalid signature'], 400); }
      return new JsonResponse(['received' => TRUE, 'duplicate' => !$this->manager->queueEvent($event)]);
    }
    catch (\Throwable $e) { $this->getLogger('commerce_lms_entitlements')->warning('Rejected PayPal subscription webhook: @message', ['@message' => $e->getMessage()]); return new JsonResponse(['error' => 'Invalid webhook'], 400); }
  }
}
