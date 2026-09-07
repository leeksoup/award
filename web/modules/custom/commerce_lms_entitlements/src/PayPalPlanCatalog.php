<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\commerce_lms_entitlements\Entity\LmsOffer;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_price\Calculator;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Discovers live PayPal plans and validates their Commerce offer mappings.
 *
 * PayPal is authoritative for recurring price and cadence, while Drupal owns
 * variation and LMS target selection. This service compares both sides but
 * never rewrites Commerce prices or Course/Class mappings from remote data.
 */
final class PayPalPlanCatalog {
  use StringTranslationTrait;

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private PayPalSubscriptionOperations $operations,
  ) {}

  /**
   * Returns labels for all live plans, grouped by their PayPal product.
   *
   * @throws \DomainException
   *   When the selected gateway is missing, is not a subscription gateway, or
   *   does not use live mode.
   */
  public function livePlanOptions(string $gateway_id): array {
    $gateway = $this->loadGateway($gateway_id, 'live');
    $products = [];
    foreach ($this->operations->listProducts($gateway) as $product) {
      if (!empty($product['id'])) {
        $products[(string) $product['id']] = (string) ($product['name'] ?? $product['id']);
      }
    }

    $options = [];
    foreach ($this->operations->listPlans($gateway) as $plan) {
      $id = (string) ($plan['id'] ?? '');
      if ($id === '') {
        continue;
      }
      $product_id = (string) ($plan['product_id'] ?? 'Unknown product');
      $group = ($products[$product_id] ?? $product_id) . ' (' . $product_id . ')';
      $options[$group][$id] = $this->planLabel($plan);
    }

    return $options;
  }

  /**
   * Validates the live half of a recurring offer and returns remote details.
   *
   * @return array{errors: string[], plan: array}
   *   Human-readable validation errors and the fetched PayPal plan.
   */
  public function validateLiveOffer(LmsOffer $offer): array {
    $errors = [];
    $plan = [];
    try {
      $gateway = $this->loadGateway($offer->getPayPalLiveGatewayId(), 'live');
      $plan = $this->operations->fetchPlan($gateway, $offer->getPayPalLivePlanId());
    }
    catch (\Throwable $e) {
      return [
        'errors' => [(string) $this->t('The live PayPal plan could not be loaded: @message', ['@message' => $e->getMessage()])],
        'plan' => [],
      ];
    }

    if (($plan['status'] ?? '') !== 'ACTIVE') {
      $errors[] = (string) $this->t('The live PayPal plan must be ACTIVE; its current status is @status.', [
        '@status' => $plan['status'] ?? 'unknown',
      ]);
    }
    if (!empty($plan['quantity_supported'])) {
      $errors[] = (string) $this->t('The live PayPal plan must not support variable quantities.');
    }

    $regular_cycles = [];
    foreach ($plan['billing_cycles'] ?? [] as $cycle) {
      if (($cycle['tenure_type'] ?? '') === 'TRIAL') {
        $errors[] = (string) $this->t('The live PayPal plan must not contain a trial billing cycle.');
      }
      elseif (($cycle['tenure_type'] ?? '') === 'REGULAR') {
        $regular_cycles[] = $cycle;
      }
    }
    if (count($regular_cycles) !== 1) {
      $errors[] = (string) $this->t('The live PayPal plan must contain exactly one regular billing cycle.');
      return ['errors' => $errors, 'plan' => $plan];
    }

    $cycle = reset($regular_cycles);
    $expected = [
      'monthly' => ['MONTH', 1],
      'quarterly' => ['MONTH', 3],
      'annual' => ['YEAR', 1],
    ][$offer->getBillingInterval()] ?? NULL;
    $frequency = $cycle['frequency'] ?? [];
    if (!$expected || ($frequency['interval_unit'] ?? '') !== $expected[0] || (int) ($frequency['interval_count'] ?? 0) !== $expected[1]) {
      $errors[] = (string) $this->t('The live PayPal billing frequency does not match the offer interval @interval.', [
        '@interval' => $offer->getBillingInterval(),
      ]);
    }

    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($offer->getVariationId());
    $variation_price = $variation?->getPrice();
    $remote_price = $cycle['pricing_scheme']['fixed_price'] ?? [];
    if (!$variation_price) {
      $errors[] = (string) $this->t('Commerce variation @variation has no price.', [
        '@variation' => $offer->getVariationId(),
      ]);
    }
    elseif (empty($remote_price['currency_code']) || !isset($remote_price['value'])) {
      $errors[] = (string) $this->t('The live PayPal regular billing cycle has no fixed price.');
    }
    elseif ($remote_price['currency_code'] !== $variation_price->getCurrencyCode() || Calculator::compare((string) $remote_price['value'], $variation_price->getNumber()) !== 0) {
      $errors[] = (string) $this->t('The live PayPal price @remote does not match Commerce price @local.', [
        '@remote' => ($remote_price['value'] ?? '?') . ' ' . ($remote_price['currency_code'] ?? '?'),
        '@local' => $variation_price->getNumber() . ' ' . $variation_price->getCurrencyCode(),
      ]);
    }

    return ['errors' => $errors, 'plan' => $plan];
  }

  /**
   * Audits every recurring offer with no configuration changes.
   *
   * @return array<string, array{configured: bool, errors: string[]}>
   *   Mapping status keyed by offer ID. Empty errors means a valid mapping.
   */
  public function auditLiveOffers(): array {
    $results = [];
    foreach ($this->entityTypeManager->getStorage('commerce_lms_offer')->loadMultiple() as $offer) {
      if (!$offer instanceof LmsOffer || $offer->getPurchaseType() !== 'recurring') {
        continue;
      }
      if ($offer->getPayPalLiveGatewayId() === '' || $offer->getPayPalLivePlanId() === '') {
        $results[$offer->id()] = [
          'configured' => FALSE,
          'errors' => [(string) $this->t('The live PayPal mapping is not configured.')],
        ];
        continue;
      }
      $results[$offer->id()] = [
        'configured' => TRUE,
        'errors' => $this->validateLiveOffer($offer)['errors'],
      ];
    }
    return $results;
  }

  /** Loads and validates one environment-specific subscription gateway. */
  public function loadGateway(string $gateway_id, string $mode): PaymentGatewayInterface {
    $gateway = $gateway_id !== ''
      ? $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($gateway_id)
      : NULL;
    if (!$gateway instanceof PaymentGatewayInterface) {
      throw new \DomainException(sprintf('Payment gateway %s does not exist.', $gateway_id ?: '(empty)'));
    }
    if ($gateway->getPluginId() !== 'paypal_checkout_subscriptions') {
      throw new \DomainException(sprintf('Payment gateway %s is not a PayPal Subscriptions gateway.', $gateway_id));
    }
    $expected_mode = $mode === 'live' ? 'live' : 'test';
    if ($gateway->getPlugin()->getMode() !== $expected_mode) {
      throw new \DomainException(sprintf('Payment gateway %s is not configured for %s mode.', $gateway_id, $mode));
    }
    return $gateway;
  }

  /** Builds a concise plan selector label from list response data. */
  private function planLabel(array $plan): string {
    $parts = [(string) ($plan['name'] ?? $plan['id'])];
    $parts[] = (string) ($plan['status'] ?? 'unknown');
    foreach ($plan['billing_cycles'] ?? [] as $cycle) {
      if (($cycle['tenure_type'] ?? '') !== 'REGULAR') {
        continue;
      }
      $price = $cycle['pricing_scheme']['fixed_price'] ?? [];
      $frequency = $cycle['frequency'] ?? [];
      $parts[] = sprintf(
        '%s %s every %d %s',
        $price['value'] ?? '?',
        $price['currency_code'] ?? '?',
        (int) ($frequency['interval_count'] ?? 0),
        strtolower((string) ($frequency['interval_unit'] ?? 'interval')),
      );
      break;
    }
    return implode(' — ', $parts) . ' — ' . $plan['id'];
  }

}
