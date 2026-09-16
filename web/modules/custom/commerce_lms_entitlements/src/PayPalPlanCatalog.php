<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\commerce_lms_entitlements\Entity\LmsOffer;
use Drupal\commerce_lms_entitlements\Entity\LmsSubscriptionCampaign;
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
   * @param \Drupal\commerce_lms_entitlements\Entity\LmsOffer $offer
   *   The offer whose live PayPal mapping should be validated.
   * @param bool $require_vip
   *   Whether an enabled offer must already have its live VIP plan mapped.
   *
   * @return array{errors: string[], plan: array}
   *   Human-readable validation errors and the fetched PayPal plan.
   */
  public function validateLiveOffer(LmsOffer $offer, bool $require_vip = TRUE): array {
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

    if ($offer->isVipEnabled()) {
      $vip_plan_id = $offer->getPayPalLiveVipPlanId();
      if ($vip_plan_id === '') {
        if ($require_vip) {
          $errors[] = (string) $this->t('The live VIP-inclusive PayPal plan is not configured.');
        }
        return ['errors' => $errors, 'plan' => $plan];
      }
      try {
        $vip_plan = $this->operations->fetchPlan($gateway, $vip_plan_id);
      }
      catch (\Throwable $e) {
        $errors[] = (string) $this->t('The live VIP-inclusive PayPal plan could not be loaded: @message', ['@message' => $e->getMessage()]);
        return ['errors' => $errors, 'plan' => $plan];
      }
      if (($vip_plan['status'] ?? '') !== 'ACTIVE') {
        $errors[] = (string) $this->t('The live VIP-inclusive PayPal plan must be ACTIVE.');
      }
      if (($vip_plan['product_id'] ?? '') !== ($plan['product_id'] ?? '')) {
        $errors[] = (string) $this->t('The base and VIP-inclusive PayPal plans must belong to the same product.');
      }
      if (!empty($vip_plan['quantity_supported'])) {
        $errors[] = (string) $this->t('The live VIP-inclusive PayPal plan must not support variable quantities.');
      }
      $vip_regular_cycles = array_values(array_filter(
        $vip_plan['billing_cycles'] ?? [],
        static fn(array $item): bool => ($item['tenure_type'] ?? '') === 'REGULAR',
      ));
      if (count($vip_regular_cycles) !== 1) {
        $errors[] = (string) $this->t('The live VIP-inclusive PayPal plan must contain exactly one regular billing cycle.');
      }
      else {
        $vip_cycle = reset($vip_regular_cycles);
        if (($vip_cycle['frequency'] ?? []) !== ($cycle['frequency'] ?? [])) {
          $errors[] = (string) $this->t('The base and VIP-inclusive PayPal plans must have the same billing frequency.');
        }
        $vip_price = $vip_cycle['pricing_scheme']['fixed_price'] ?? [];
        $expected_currency = $variation_price?->getCurrencyCode();
        $expected_number = $variation_price
          ? Calculator::add($variation_price->getNumber(), $offer->getVipSurchargeNumber())
          : NULL;
        if (!$expected_number || ($vip_price['currency_code'] ?? '') !== $expected_currency || Calculator::compare((string) ($vip_price['value'] ?? ''), $expected_number) !== 0) {
          $errors[] = (string) $this->t('The live VIP plan price must equal the Commerce price plus the VIP surcharge (@amount @currency).', [
            '@amount' => $expected_number ?? '?',
            '@currency' => $expected_currency ?? '?',
          ]);
        }
        if ($offer->getVipSurchargeCurrency() !== $expected_currency) {
          $errors[] = (string) $this->t('The VIP surcharge currency must match the Commerce variation currency.');
        }
      }
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

  /**
   * Validates all configured PayPal plans for one subscription campaign.
   *
   * @return string[]
   *   Human-readable errors. An empty array means all mappings are valid.
   */
  public function validateCampaign(LmsSubscriptionCampaign $campaign): array {
    $errors = [];
    foreach ($campaign->getOfferMappings() as $offer_id => $mapping) {
      $offer = $this->entityTypeManager->getStorage('commerce_lms_offer')->load($offer_id);
      if (!$offer instanceof LmsOffer || $offer->getPurchaseType() !== 'recurring') {
        $errors[] = sprintf('Offer %s is missing or is not recurring.', $offer_id);
        continue;
      }
      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($offer->getVariationId());
      $base_price = $variation?->getPrice();
      if (!$base_price) {
        $errors[] = sprintf('Offer %s has no Commerce variation price.', $offer_id);
        continue;
      }

      foreach (['sandbox', 'live'] as $environment) {
        $gateway_id = $environment === 'live'
          ? $offer->getPayPalLiveGatewayId()
          : $offer->getPayPalSandboxGatewayId();
        $standard_plan_id = $environment === 'live'
          ? $offer->getPayPalLivePlanId()
          : $offer->getPayPalSandboxPlanId();
        $tiers = $campaign->forcesVip() ? [TRUE] : [FALSE, TRUE];
        $configured_campaign_plans = array_filter(array_map(
          fn(bool $vip): string => $campaign->getPayPalPlanId($offer_id, $environment, $vip),
          $tiers,
        ));
        if (!$configured_campaign_plans && $environment !== $offer->getPayPalEnvironment()) {
          continue;
        }
        if ($gateway_id === '' || $standard_plan_id === '') {
          $errors[] = sprintf('%s/%s requires the %s gateway and standard plan mapping.', $campaign->id(), $offer_id, $environment);
          continue;
        }
        try {
          $gateway = $this->loadGateway($gateway_id, $environment);
          $standard_plan = $this->operations->fetchPlan($gateway, $standard_plan_id);
        }
        catch (\Throwable $e) {
          $errors[] = sprintf('%s/%s %s standard plan could not be loaded: %s', $campaign->id(), $offer_id, $environment, $e->getMessage());
          continue;
        }

        foreach ($tiers as $vip) {
          if ($vip && !$offer->isVipEnabled()) {
            if ($campaign->forcesVip()) {
              $errors[] = sprintf('%s/%s forces VIP but the offer does not enable VIP.', $campaign->id(), $offer_id);
            }
            continue;
          }
          $tier = $vip ? 'VIP' : 'base';
          $plan_id = $campaign->getPayPalPlanId($offer_id, $environment, $vip);
          if ($plan_id === '') {
            $errors[] = sprintf('%s/%s has no %s %s campaign plan.', $campaign->id(), $offer_id, $environment, $tier);
            continue;
          }
          $intro_price = $campaign->getIntroPrice($offer_id, $vip);
          if (!$intro_price) {
            $errors[] = sprintf('%s/%s has no valid %s introductory price.', $campaign->id(), $offer_id, $tier);
            continue;
          }
          if ($vip && $offer->getVipSurchargeCurrency() !== $base_price->getCurrencyCode()) {
            $errors[] = sprintf('%s/%s VIP surcharge currency does not match the variation.', $campaign->id(), $offer_id);
            continue;
          }
          $regular_price = $vip
            ? $base_price->add(new \Drupal\commerce_price\Price($offer->getVipSurchargeNumber(), $offer->getVipSurchargeCurrency()))
            : $base_price;
          try {
            $plan = $this->operations->fetchPlan($gateway, $plan_id);
          }
          catch (\Throwable $e) {
            $errors[] = sprintf('%s/%s %s %s plan could not be loaded: %s', $campaign->id(), $offer_id, $environment, $tier, $e->getMessage());
            continue;
          }
          foreach ($this->validateCampaignPlan($campaign, $offer, $plan, $standard_plan, $intro_price, $regular_price) as $error) {
            $errors[] = sprintf('%s/%s %s %s: %s', $campaign->id(), $offer_id, $environment, $tier, $error);
          }
        }
      }
    }
    return $errors;
  }

  /** Validates one remote campaign plan against its configured schedule. */
  public function validateCampaignPlan(LmsSubscriptionCampaign $campaign, LmsOffer $offer, array $plan, array $standard_plan, \Drupal\commerce_price\Price $intro_price, \Drupal\commerce_price\Price $regular_price): array {
    $errors = [];
    if ($intro_price->getCurrencyCode() !== $regular_price->getCurrencyCode() || !$regular_price->greaterThan($intro_price)) {
      $errors[] = 'the introductory price must be lower than the regular price in the same currency';
    }
    if (($plan['status'] ?? '') !== 'ACTIVE') {
      $errors[] = 'the plan is not ACTIVE';
    }
    if (!empty($plan['quantity_supported'])) {
      $errors[] = 'the plan supports variable quantities';
    }
    if (($plan['product_id'] ?? '') === '' || ($plan['product_id'] ?? '') !== ($standard_plan['product_id'] ?? '')) {
      $errors[] = 'the plan does not use the offer’s standard PayPal product';
    }
    $trial_cycles = array_values(array_filter($plan['billing_cycles'] ?? [], static fn(array $cycle): bool => ($cycle['tenure_type'] ?? '') === 'TRIAL'));
    $regular_cycles = array_values(array_filter($plan['billing_cycles'] ?? [], static fn(array $cycle): bool => ($cycle['tenure_type'] ?? '') === 'REGULAR'));
    if (count($trial_cycles) !== 1 || count($regular_cycles) !== 1) {
      $errors[] = 'the plan must contain exactly one TRIAL and one REGULAR billing cycle';
      return $errors;
    }
    $trial = $trial_cycles[0];
    $regular = $regular_cycles[0];
    if ((int) ($trial['sequence'] ?? 0) >= (int) ($regular['sequence'] ?? 0)) {
      $errors[] = 'the TRIAL cycle must precede the REGULAR cycle';
    }
    if ((int) ($trial['total_cycles'] ?? 0) !== $campaign->getIntroCycles($offer->id())) {
      $errors[] = 'the TRIAL cycle count does not match the campaign';
    }
    if ((int) ($regular['total_cycles'] ?? -1) !== 0) {
      $errors[] = 'the REGULAR cycle must continue indefinitely';
    }
    $expected_frequency = [
      'monthly' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
      'quarterly' => ['interval_unit' => 'MONTH', 'interval_count' => 3],
      'annual' => ['interval_unit' => 'YEAR', 'interval_count' => 1],
    ][$offer->getBillingInterval()] ?? [];
    foreach (['TRIAL' => $trial, 'REGULAR' => $regular] as $name => $cycle) {
      $frequency = $cycle['frequency'] ?? [];
      if (($frequency['interval_unit'] ?? '') !== ($expected_frequency['interval_unit'] ?? NULL) || (int) ($frequency['interval_count'] ?? 0) !== ($expected_frequency['interval_count'] ?? 0)) {
        $errors[] = sprintf('the %s frequency does not match the offer cadence', $name);
      }
    }
    foreach ([['TRIAL', $trial, $intro_price], ['REGULAR', $regular, $regular_price]] as [$name, $cycle, $expected_price]) {
      $remote_price = $cycle['pricing_scheme']['fixed_price'] ?? [];
      if (!isset($remote_price['value']) || empty($remote_price['currency_code'])) {
        $errors[] = sprintf('the %s cycle has no fixed price', $name);
      }
      elseif ($remote_price['currency_code'] !== $expected_price->getCurrencyCode() || Calculator::compare((string) $remote_price['value'], $expected_price->getNumber()) !== 0) {
        $errors[] = sprintf('the %s price does not match %s %s', $name, $expected_price->getNumber(), $expected_price->getCurrencyCode());
      }
    }
    return $errors;
  }

  /** Validates one standard VIP plan against its base plan and offer. */
  public function validateStandardVipPlan(LmsOffer $offer, array $plan, array $standard_plan, \Drupal\commerce_price\Price $vip_price): array {
    $errors = [];
    if (($plan['status'] ?? '') !== 'ACTIVE') {
      $errors[] = 'the plan is not ACTIVE';
    }
    if (!empty($plan['quantity_supported'])) {
      $errors[] = 'the plan supports variable quantities';
    }
    if (($plan['product_id'] ?? '') === '' || ($plan['product_id'] ?? '') !== ($standard_plan['product_id'] ?? '')) {
      $errors[] = 'the plan does not use the offer’s standard PayPal product';
    }
    $trials = array_filter($plan['billing_cycles'] ?? [], static fn(array $cycle): bool => ($cycle['tenure_type'] ?? '') === 'TRIAL');
    $regular = array_values(array_filter($plan['billing_cycles'] ?? [], static fn(array $cycle): bool => ($cycle['tenure_type'] ?? '') === 'REGULAR'));
    if ($trials || count($regular) !== 1) {
      $errors[] = 'the plan must contain one REGULAR billing cycle and no TRIAL cycles';
      return $errors;
    }
    if ((int) ($regular[0]['total_cycles'] ?? -1) !== 0) {
      $errors[] = 'the REGULAR cycle must continue indefinitely';
    }
    $standard_regular = array_values(array_filter($standard_plan['billing_cycles'] ?? [], static fn(array $cycle): bool => ($cycle['tenure_type'] ?? '') === 'REGULAR'));
    $expected_frequency = [
      'monthly' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
      'quarterly' => ['interval_unit' => 'MONTH', 'interval_count' => 3],
      'annual' => ['interval_unit' => 'YEAR', 'interval_count' => 1],
    ][$offer->getBillingInterval()] ?? [];
    if (count($standard_regular) !== 1 || ($regular[0]['frequency'] ?? []) !== $expected_frequency || ($standard_regular[0]['frequency'] ?? []) !== $expected_frequency) {
      $errors[] = 'the plan frequency does not match the standard base plan';
    }
    $remote_price = $regular[0]['pricing_scheme']['fixed_price'] ?? [];
    if (!isset($remote_price['value']) || empty($remote_price['currency_code'])) {
      $errors[] = 'the REGULAR cycle has no fixed price';
    }
    elseif ($remote_price['currency_code'] !== $vip_price->getCurrencyCode() || Calculator::compare((string) $remote_price['value'], $vip_price->getNumber()) !== 0) {
      $errors[] = sprintf('the REGULAR price does not match %s %s', $vip_price->getNumber(), $vip_price->getCurrencyCode());
    }
    return $errors;
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
