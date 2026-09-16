<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Performs PayPal API operations not exposed by the contributed checkout SDK.
 *
 * Checkout, captures, and webhook signature verification remain with the
 * contributed Commerce gateways. This deliberately small adapter is limited
 * to subscription lifecycle operations needed by the LMS access policy.
 */
final class PayPalSubscriptionOperations {
  public function __construct(private ClientInterface $client) {}

  /**
   * Lists every PayPal catalog product visible to a configured gateway.
   *
   * PayPal limits catalog pages to 20 records. Following the reported page
   * count keeps the administrator picker complete without trusting URLs from
   * the remote response.
   */
  public function listProducts(object $gateway): array {
    return $this->listCollection($gateway, '/v1/catalogs/products', 'products');
  }

  /**
   * Lists every PayPal billing plan visible to a configured gateway.
   *
   * `Prefer: return=representation` requests billing-cycle and pricing data
   * for useful labels. The selected plan is still fetched independently when
   * an offer is validated.
   */
  public function listPlans(object $gateway): array {
    return $this->listCollection($gateway, '/v1/billing/plans', 'plans', [
      'Prefer' => 'return=representation',
    ]);
  }

  /** Fetches authoritative details for one PayPal billing plan. */
  public function fetchPlan(object $gateway, string $plan_id): array {
    $config = $gateway->getPluginConfiguration();
    $base = $this->baseUrl($config);
    $response = $this->client->get($base . '/v1/billing/plans/' . rawurlencode($plan_id), [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->accessToken($base, $config),
        'Content-Type' => 'application/json',
        'Prefer' => 'return=representation',
      ],
    ]);
    return json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /** Creates one fixed-price subscription plan with an idempotency key. */
  public function createPlan(object $gateway, array $plan, string $request_id): array {
    $config = $gateway->getPluginConfiguration();
    $base = $this->baseUrl($config);
    try {
      $response = $this->client->post($base . '/v1/billing/plans', [
        'headers' => [
          'Authorization' => 'Bearer ' . $this->accessToken($base, $config),
          'Content-Type' => 'application/json',
          'PayPal-Request-Id' => $request_id,
          'Prefer' => 'return=representation',
        ],
        'json' => $plan,
      ]);
    }
    catch (RequestException $e) {
      $details = $e->hasResponse() ? trim((string) $e->getResponse()->getBody()) : '';
      throw new \RuntimeException(
        'PayPal rejected the billing plan request' . ($details !== '' ? ': ' . $details : '.'),
        0,
        $e,
      );
    }
    $body = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (empty($body['id'])) {
      throw new \RuntimeException('PayPal did not return an ID for the created billing plan.');
    }
    return $body;
  }

  /**
   * Cancels future billing and fetches the post-cancellation source of truth.
   *
   * The cancel endpoint does not itself provide access-through data, so the
   * follow-up GET is required before EntitlementManager changes local access.
   */
  public function cancel(object $gateway, string $subscription_id): array {
    $config = $gateway->getPluginConfiguration();
    $base = $this->baseUrl($config);
    $token = $this->accessToken($base, $config);
    $response = $this->client->post($base . '/v1/billing/subscriptions/' . rawurlencode($subscription_id) . '/cancel', [
      'headers' => ['Authorization' => 'Bearer ' . $token],
      'json' => ['reason' => 'Cancelled by purchaser through Drupal.'],
    ]);
    if ($response->getStatusCode() !== 204) {
      throw new \RuntimeException('PayPal did not accept the subscription cancellation.');
    }
    return $this->fetchSubscription($gateway, $subscription_id);
  }

  /** Fetches subscription detail using the same configured gateway credentials. */
  public function fetchSubscription(object $gateway, string $subscription_id): array {
    $config = $gateway->getPluginConfiguration();
    $base = $this->baseUrl($config);
    $response = $this->client->get($base . '/v1/billing/subscriptions/' . rawurlencode($subscription_id), ['headers' => ['Authorization' => 'Bearer ' . $this->accessToken($base, $config)]]);
    return json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Starts a plan revision and returns PayPal's buyer approval URL.
   */
  public function revise(object $gateway, string $subscription_id, string $plan_id, string $return_url, string $cancel_url, string $request_id): string {
    $config = $gateway->getPluginConfiguration();
    $base = $this->baseUrl($config);
    $response = $this->client->post($base . '/v1/billing/subscriptions/' . rawurlencode($subscription_id) . '/revise', [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->accessToken($base, $config),
        'Content-Type' => 'application/json',
        'PayPal-Request-Id' => $request_id,
      ],
      'json' => [
        'plan_id' => $plan_id,
        'application_context' => [
          'return_url' => $return_url,
          'cancel_url' => $cancel_url,
        ],
      ],
    ]);
    $body = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    foreach ($body['links'] ?? [] as $link) {
      if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
        return (string) $link['href'];
      }
    }
    throw new \RuntimeException('PayPal did not return an approval URL for the plan revision.');
  }

  /**
   * Refunds the initially recorded capture and returns PayPal's immutable ID.
   *
   * Exceptions deliberately propagate to the guarantee form, which records
   * recovery work while retaining the immediate access revocation.
   */
  public function refundCapture(object $gateway, string $capture_id): string {
    $config = $gateway->getPluginConfiguration();
    $base = $this->baseUrl($config);
    $response = $this->client->post($base . '/v2/payments/captures/' . rawurlencode($capture_id) . '/refund', ['headers' => ['Authorization' => 'Bearer ' . $this->accessToken($base, $config)]]);
    $body = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (empty($body['id'])) {
      throw new \RuntimeException('PayPal did not return a refund ID.');
    }
    return (string) $body['id'];
  }

  /** Obtains a short-lived OAuth token without persisting a duplicate secret. */
  private function accessToken(string $base, array $config): string {
    $client_id = (string) ($config['client_id'] ?? '');
    $secret = (string) ($config['client_secret'] ?? $config['secret'] ?? '');
    if ($client_id === '' || $secret === '') {
      throw new \RuntimeException('The PayPal payment gateway is missing API credentials.');
    }
    $response = $this->client->post($base . '/v1/oauth2/token', ['auth' => [$client_id, $secret], 'form_params' => ['grant_type' => 'client_credentials']]);
    $body = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (empty($body['access_token'])) {
      throw new \RuntimeException('PayPal did not return an access token.');
    }
    return (string) $body['access_token'];
  }

  /** Chooses the PayPal sandbox only when the gateway is not explicitly live. */
  private function baseUrl(array $config): string {
    return ($config['mode'] ?? 'test') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
  }

  /** Retrieves a complete paginated PayPal collection. */
  private function listCollection(object $gateway, string $path, string $key, array $extra_headers = []): array {
    $config = $gateway->getPluginConfiguration();
    $base = $this->baseUrl($config);
    $token = $this->accessToken($base, $config);
    $items = [];
    $page = 1;
    do {
      $response = $this->client->get($base . $path, [
        'headers' => $extra_headers + [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
        ],
        'query' => [
          'page_size' => 20,
          'page' => $page,
          'total_required' => 'true',
        ],
      ]);
      $body = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      foreach ($body[$key] ?? [] as $item) {
        if (is_array($item)) {
          $items[] = $item;
        }
      }
      $total_pages = max(1, (int) ($body['total_pages'] ?? 1));
      $page++;
    } while ($page <= $total_pages);

    return $items;
  }
}
