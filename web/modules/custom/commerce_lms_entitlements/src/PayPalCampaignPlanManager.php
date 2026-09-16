<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\commerce_lms_entitlements\Entity\LmsOffer;
use Drupal\commerce_lms_entitlements\Entity\LmsSubscriptionCampaign;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/** Previews and idempotently creates a campaign's PayPal plan matrix. */
final class PayPalCampaignPlanManager {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private PayPalSubscriptionOperations $operations,
    private PayPalPlanCatalog $planCatalog,
    private StateInterface $state,
  ) {}

  /**
   * Previews or creates every required plan for one environment.
   *
   * @return array<int, array<string, mixed>>
   *   One result per offer and tier.
   */
  public function provision(LmsSubscriptionCampaign $campaign, string $environment, bool $apply = FALSE): array {
    if (!in_array($environment, ['sandbox', 'live'], TRUE)) {
      throw new \InvalidArgumentException('Environment must be sandbox or live.');
    }

    $entries = $this->buildEntries($campaign, $environment);
    if (!$apply) {
      return $entries;
    }

    $state_key = 'commerce_lms_entitlements.campaign_plan_creation.' . $environment . '.' . $campaign->id();
    $pending = $this->state->get($state_key, []);
    $pending = is_array($pending) ? $pending : [];
    $mappings = $campaign->getOfferMappings();

    foreach ($entries as &$entry) {
      if ($entry['status'] === 'configured') {
        continue;
      }

      $plan_id = trim((string) ($pending[$entry['spec_hash']] ?? ''));
      if ($plan_id !== '') {
        $plan = $this->operations->fetchPlan($entry['gateway'], $plan_id);
        $errors = $this->validatePlan($campaign, $entry, $plan);
        if ($errors) {
          throw new \RuntimeException(sprintf(
            'Previously created PayPal plan %s is invalid for %s/%s: %s',
            $plan_id,
            $entry['offer_id'],
            $entry['tier'],
            implode('; ', $errors),
          ));
        }
        $entry['status'] = 'recovered';
      }
      else {
        $created = $this->operations->createPlan($entry['gateway'], $entry['payload'], $entry['request_id']);
        $plan_id = trim((string) ($created['id'] ?? ''));
        $pending[$entry['spec_hash']] = $plan_id;
        $this->state->set($state_key, $pending);
        $plan = $this->operations->fetchPlan($entry['gateway'], $plan_id);
        $errors = $this->validatePlan($campaign, $entry, $plan);
        if ($errors) {
          throw new \RuntimeException(sprintf(
            'Created PayPal plan %s is invalid for %s/%s: %s',
            $plan_id,
            $entry['offer_id'],
            $entry['tier'],
            implode('; ', $errors),
          ));
        }
        $entry['status'] = 'created';
      }

      $entry['plan_id'] = $plan_id;
      $mappings[$entry['offer_id']][$entry['mapping_key']] = $plan_id;
    }
    unset($entry);

    $campaign->set('offer_mappings', $mappings);
    $campaign->save();
    $this->state->delete($state_key);
    return $entries;
  }

  /** Builds and validates the complete matrix before any remote mutation. */
  private function buildEntries(LmsSubscriptionCampaign $campaign, string $environment): array {
    $entries = [];
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

      $gateway_id = $environment === 'live' ? $offer->getPayPalLiveGatewayId() : $offer->getPayPalSandboxGatewayId();
      $standard_plan_id = $environment === 'live' ? $offer->getPayPalLivePlanId() : $offer->getPayPalSandboxPlanId();
      if ($gateway_id === '' || $standard_plan_id === '') {
        $errors[] = sprintf('Offer %s has no %s gateway and standard plan mapping.', $offer_id, $environment);
        continue;
      }
      try {
        $gateway = $this->planCatalog->loadGateway($gateway_id, $environment);
        $standard_plan = $this->operations->fetchPlan($gateway, $standard_plan_id);
      }
      catch (\Throwable $e) {
        $errors[] = sprintf('Offer %s standard %s plan could not be loaded: %s', $offer_id, $environment, $e->getMessage());
        continue;
      }
      foreach ($this->validateStandardPlan($offer, $standard_plan, $base_price, $environment) as $error) {
        $errors[] = sprintf('Offer %s: %s', $offer_id, $error);
      }
      $product_id = trim((string) ($standard_plan['product_id'] ?? ''));
      if ($product_id === '') {
        continue;
      }
      $configured_product_id = $environment === 'live' ? $offer->getPayPalLiveProductId() : $offer->getPayPalSandboxProductId();
      if ($configured_product_id !== '' && $configured_product_id !== $product_id) {
        $errors[] = sprintf('Offer %s configured %s product %s does not match standard plan product %s.', $offer_id, $environment, $configured_product_id, $product_id);
        continue;
      }

      $intro_cycles = $campaign->getIntroCycles($offer_id);
      if ($intro_cycles < 1 || $intro_cycles > 999) {
        $errors[] = sprintf('Offer %s introductory cycle count must be between 1 and 999.', $offer_id);
        continue;
      }

      $tiers = $campaign->forcesVip() ? [TRUE] : [FALSE, TRUE];
      foreach ($tiers as $vip) {
        if ($vip && !$offer->isVipEnabled()) {
          if ($campaign->forcesVip()) {
            $errors[] = sprintf('Offer %s must enable VIP for forced-VIP campaign %s.', $offer_id, $campaign->id());
          }
          continue;
        }
        $tier = $vip ? 'vip' : 'base';
        $intro_price = $campaign->getIntroPrice($offer_id, $vip);
        if (!$intro_price) {
          $errors[] = sprintf('Offer %s has no valid %s introductory price.', $offer_id, $tier);
          continue;
        }
        if ($vip && $offer->getVipSurchargeCurrency() !== $base_price->getCurrencyCode()) {
          $errors[] = sprintf('Offer %s VIP surcharge currency does not match its Commerce price.', $offer_id);
          continue;
        }
        $regular_price = $vip
          ? $base_price->add(new Price($offer->getVipSurchargeNumber(), $offer->getVipSurchargeCurrency()))
          : $base_price;
        if ($intro_price->getCurrencyCode() !== $regular_price->getCurrencyCode() || !$regular_price->greaterThan($intro_price)) {
          $errors[] = sprintf('Offer %s %s introductory price must be lower than its regular price in the same currency.', $offer_id, $tier);
          continue;
        }

        $payload = $this->buildPayload($campaign, $offer, $environment, $vip, $product_id, $intro_price, $regular_price, $standard_plan);
        $spec_hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $request_id = 'cle-' . substr($spec_hash, 0, 34);
        $mapping_key = 'paypal_' . $environment . '_' . $tier . '_plan_id';
        $plan_id = trim((string) ($mapping[$mapping_key] ?? ''));
        $entry = [
          'offer_id' => $offer_id,
          'offer_label' => (string) $offer->label(),
          'tier' => $tier,
          'environment' => $environment,
          'intro' => $intro_price->getNumber() . ' ' . $intro_price->getCurrencyCode(),
          'regular' => $regular_price->getNumber() . ' ' . $regular_price->getCurrencyCode(),
          'intro_cycles' => $campaign->getIntroCycles($offer_id),
          'product_id' => $product_id,
          'plan_id' => $plan_id,
          'status' => $plan_id === '' ? 'would create' : 'configured',
          'request_id' => $request_id,
          'spec_hash' => $spec_hash,
          'mapping_key' => $mapping_key,
          'payload' => $payload,
          'gateway' => $gateway,
          'standard_plan' => $standard_plan,
          'offer' => $offer,
          'intro_price' => $intro_price,
          'regular_price' => $regular_price,
        ];
        if ($plan_id !== '') {
          try {
            $plan = $this->operations->fetchPlan($gateway, $plan_id);
            foreach ($this->validatePlan($campaign, $entry, $plan) as $error) {
              $errors[] = sprintf('Configured plan %s for %s/%s is invalid: %s', $plan_id, $offer_id, $tier, $error);
            }
          }
          catch (\Throwable $e) {
            $errors[] = sprintf('Configured plan %s for %s/%s could not be loaded: %s', $plan_id, $offer_id, $tier, $e->getMessage());
          }
        }
        $entries[] = $entry;
      }
    }

    if ($errors) {
      throw new \RuntimeException("PayPal plan matrix validation failed:\n- " . implode("\n- ", $errors));
    }
    if (!$entries) {
      throw new \RuntimeException('The campaign has no recurring offer tiers to provision.');
    }
    return $entries;
  }

  /** Validates the standard plan used as the matrix source. */
  private function validateStandardPlan(LmsOffer $offer, array $plan, Price $base_price, string $environment): array {
    $errors = [];
    if (($plan['status'] ?? '') !== 'ACTIVE') {
      $errors[] = sprintf('the %s standard plan is not ACTIVE', $environment);
    }
    if (!empty($plan['quantity_supported'])) {
      $errors[] = sprintf('the %s standard plan supports variable quantities', $environment);
    }
    $trials = array_filter($plan['billing_cycles'] ?? [], static fn(array $cycle): bool => ($cycle['tenure_type'] ?? '') === 'TRIAL');
    $regular = array_values(array_filter($plan['billing_cycles'] ?? [], static fn(array $cycle): bool => ($cycle['tenure_type'] ?? '') === 'REGULAR'));
    if ($trials || count($regular) !== 1) {
      $errors[] = sprintf('the %s standard plan must have one REGULAR cycle and no TRIAL cycles', $environment);
      return $errors;
    }
    $frequency = $regular[0]['frequency'] ?? [];
    $expected_frequency = $this->frequency($offer);
    if (($frequency['interval_unit'] ?? '') !== $expected_frequency['interval_unit'] || (int) ($frequency['interval_count'] ?? 0) !== $expected_frequency['interval_count']) {
      $errors[] = sprintf('the %s standard plan cadence does not match the offer', $environment);
    }
    $remote_price = $regular[0]['pricing_scheme']['fixed_price'] ?? [];
    if (!isset($remote_price['value']) || empty($remote_price['currency_code'])) {
      $errors[] = sprintf('the %s standard plan has no fixed regular price', $environment);
    }
    elseif ($remote_price['currency_code'] !== $base_price->getCurrencyCode() || Calculator::compare((string) $remote_price['value'], $base_price->getNumber()) !== 0) {
      $errors[] = sprintf('the %s standard plan price does not match the Commerce variation', $environment);
    }
    if (empty($plan['product_id'])) {
      $errors[] = sprintf('the %s standard plan has no product ID', $environment);
    }
    return $errors;
  }

  /** Creates a deterministic PayPal request body. */
  private function buildPayload(LmsSubscriptionCampaign $campaign, LmsOffer $offer, string $environment, bool $vip, string $product_id, Price $intro_price, Price $regular_price, array $standard_plan): array {
    $tier = $vip ? 'VIP' : 'Base';
    $environment_label = $environment === 'live' ? 'Live' : 'Sandbox';
    $name = mb_substr(sprintf('Commerce LMS - %s - %s - %s - %s', $campaign->id(), $offer->id(), $tier, $environment_label), 0, 127);
    $description = mb_substr(sprintf('Campaign %s introductory %s plan for offer %s.', $campaign->id(), strtolower($tier), $offer->id()), 0, 127);
    $frequency = $this->frequency($offer);
    $standard_preferences = is_array($standard_plan['payment_preferences'] ?? NULL)
      ? $standard_plan['payment_preferences']
      : [];
    $payload = [
      'product_id' => $product_id,
      'name' => $name,
      'description' => $description,
      'billing_cycles' => [
        [
          'frequency' => $frequency,
          'tenure_type' => 'TRIAL',
          'sequence' => 1,
          'total_cycles' => $campaign->getIntroCycles($offer->id()),
          'pricing_scheme' => [
            'fixed_price' => [
              'value' => $intro_price->getNumber(),
              'currency_code' => $intro_price->getCurrencyCode(),
            ],
          ],
        ],
        [
          'frequency' => $frequency,
          'tenure_type' => 'REGULAR',
          'sequence' => 2,
          'total_cycles' => 0,
          'pricing_scheme' => [
            'fixed_price' => [
              'value' => $regular_price->getNumber(),
              'currency_code' => $regular_price->getCurrencyCode(),
            ],
          ],
        ],
      ],
      'quantity_supported' => FALSE,
      'payment_preferences' => [
        'auto_bill_outstanding' => (bool) ($standard_preferences['auto_bill_outstanding'] ?? TRUE),
        'setup_fee' => [
          'value' => '0',
          'currency_code' => $intro_price->getCurrencyCode(),
        ],
        'setup_fee_failure_action' => 'CONTINUE',
        'payment_failure_threshold' => (int) ($standard_preferences['payment_failure_threshold'] ?? 1),
      ],
    ];
    return $payload;
  }

  /** Returns the PayPal cadence for one LMS offer. */
  private function frequency(LmsOffer $offer): array {
    return match ($offer->getBillingInterval()) {
      'monthly' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
      'quarterly' => ['interval_unit' => 'MONTH', 'interval_count' => 3],
      'annual' => ['interval_unit' => 'YEAR', 'interval_count' => 1],
      default => throw new \DomainException(sprintf('Unsupported billing interval %s.', $offer->getBillingInterval())),
    };
  }

  /** Validates a configured, recovered, or newly created campaign plan. */
  private function validatePlan(LmsSubscriptionCampaign $campaign, array $entry, array $plan): array {
    return $this->planCatalog->validateCampaignPlan(
      $campaign,
      $entry['offer'],
      $plan,
      $entry['standard_plan'],
      $entry['intro_price'],
      $entry['regular_price'],
    );
  }

}
