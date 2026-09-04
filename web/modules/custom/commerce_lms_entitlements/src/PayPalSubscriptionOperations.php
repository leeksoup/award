<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use GuzzleHttp\ClientInterface;

/**
 * Performs PayPal API operations not exposed by the contributed checkout SDK.
 *
 * Checkout, captures, and webhook signature verification remain with the
 * contributed Commerce gateways. This deliberately small adapter is limited
 * to subscription cancellation and initial-capture refunds needed by the
 * LMS guarantee policy.
 */
final class PayPalSubscriptionOperations {
  public function __construct(private ClientInterface $client) {}

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
}
