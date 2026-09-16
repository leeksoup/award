<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\commerce_lms_entitlements\Entity\LmsOffer;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/** Previews and idempotently creates standard VIP-inclusive PayPal plans. */
final class PayPalVipPlanManager {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private PayPalSubscriptionOperations $operations,
    private PayPalPlanCatalog $planCatalog,
    private StateInterface $state,
  ) {}

  /**
   * Previews or creates every missing VIP plan for one environment.
   *
   * @return array<int, array<string, mixed>>
   *   One result per recurring, VIP-enabled offer.
   */
  public function provision(string $environment, bool $apply = FALSE): array {
    if (!in_array($environment, ['sandbox', 'live'], TRUE)) {
      throw new \InvalidArgumentException('Environment must be sandbox or live.');
    }

    $entries = $this->buildEntries($environment);
    if (!$apply) {
      return $entries;
    }

    $state_key = 'commerce_lms_entitlements.vip_plan_creation.' . $environment;
    $pending = $this->state->get($state_key, []);
    $pending = is_array($pending) ? $pending : [];
    foreach ($entries as &$entry) {
      if ($entry['status'] === 'configured') {
        continue;
      }

      $plan_id = trim((string) ($pending[$entry['spec_hash']] ?? ''));
      if ($plan_id !== '') {
        $plan = $this->operations->fetchPlan($entry['gateway'], $plan_id);
        $errors = $this->validatePlan($entry, $plan);
        if ($errors) {
          throw new \RuntimeException(sprintf(
            'Previously created PayPal VIP plan %s is invalid for %s: %s',
            $plan_id,
            $entry['offer_id'],
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
        $errors = $this->validatePlan($entry, $plan);
        if ($errors) {
          throw new \RuntimeException(sprintf(
            'Created PayPal VIP plan %s is invalid for %s: %s',
            $plan_id,
            $entry['offer_id'],
            implode('; ', $errors),
          ));
        }
        $entry['status'] = 'created';
      }

      $entry['plan_id'] = $plan_id;
      $entry['offer']->set($entry['mapping_key'], $plan_id);
      $entry['offer']->save();
      unset($pending[$entry['spec_hash']]);
      if ($pending) {
        $this->state->set($state_key, $pending);
      }
      else {
        $this->state->delete($state_key);
      }
    }
    unset($entry);

    return $entries;
  }

  /** Builds and validates the complete plan list before remote mutation. */
  private function buildEntries(string $environment): array {
    $entries = [];
    $errors = [];
    $offers = $this->entityTypeManager->getStorage('commerce_lms_offer')->loadMultiple();
    foreach ($offers as $offer) {
      if (!$offer instanceof LmsOffer || $offer->getPurchaseType() !== 'recurring' || !$offer->isVipEnabled()) {
        continue;
      }

      $offer_id = $offer->id();
      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($offer->getVariationId());
      $base_price = $variation?->getPrice();
      if (!$base_price) {
        $errors[] = sprintf('Offer %s has no Commerce variation price.', $offer_id);
        continue;
      }
      if (!is_numeric($offer->getVipSurchargeNumber()) || Calculator::compare($offer->getVipSurchargeNumber(), '0') <= 0) {
        $errors[] = sprintf('Offer %s must have a positive VIP surcharge.', $offer_id);
        continue;
      }
      if ($offer->getVipSurchargeCurrency() !== $base_price->getCurrencyCode()) {
        $errors[] = sprintf('Offer %s VIP surcharge currency does not match its Commerce price.', $offer_id);
        continue;
      }
      $vip_price = $base_price->add(new Price($offer->getVipSurchargeNumber(), $offer->getVipSurchargeCurrency()));

      $gateway_id = $environment === 'live' ? $offer->getPayPalLiveGatewayId() : $offer->getPayPalSandboxGatewayId();
      $standard_plan_id = $environment === 'live' ? $offer->getPayPalLivePlanId() : $offer->getPayPalSandboxPlanId();
      if ($gateway_id === '' || $standard_plan_id === '') {
        $errors[] = sprintf('Offer %s has no %s gateway and standard base plan mapping.', $offer_id, $environment);
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
      foreach ($this->validateBasePlan($offer, $standard_plan, $base_price, $environment) as $error) {
        $errors[] = sprintf('Offer %s: %s', $offer_id, $error);
      }
      $product_id = trim((string) ($standard_plan['product_id'] ?? ''));
      if ($product_id === '') {
        continue;
      }

      $payload = $this->buildPayload($offer, $environment, $product_id, $vip_price, $standard_plan);
      $spec_hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
      $mapping_key = 'paypal_' . $environment . '_vip_plan_id';
      $plan_id = $environment === 'live' ? $offer->getPayPalLiveVipPlanId() : $offer->getPayPalSandboxVipPlanId();
      $entry = [
        'offer_id' => $offer_id,
        'offer_label' => (string) $offer->label(),
        'environment' => $environment,
        'price' => $vip_price->getNumber() . ' ' . $vip_price->getCurrencyCode(),
        'product_id' => $product_id,
        'plan_id' => $plan_id,
        'status' => $plan_id === '' ? 'would create' : 'configured',
        'request_id' => 'cle-' . substr($spec_hash, 0, 34),
        'spec_hash' => $spec_hash,
        'mapping_key' => $mapping_key,
        'payload' => $payload,
        'gateway' => $gateway,
        'standard_plan' => $standard_plan,
        'offer' => $offer,
        'vip_price' => $vip_price,
      ];
      if ($plan_id !== '') {
        try {
          $plan = $this->operations->fetchPlan($gateway, $plan_id);
          foreach ($this->validatePlan($entry, $plan) as $error) {
            $errors[] = sprintf('Configured VIP plan %s for %s is invalid: %s', $plan_id, $offer_id, $error);
          }
        }
        catch (\Throwable $e) {
          $errors[] = sprintf('Configured VIP plan %s for %s could not be loaded: %s', $plan_id, $offer_id, $e->getMessage());
        }
      }
      $entries[] = $entry;
    }

    if ($errors) {
      throw new \RuntimeException("PayPal VIP plan validation failed:\n- " . implode("\n- ", $errors));
    }
    if (!$entries) {
      throw new \RuntimeException('There are no recurring, VIP-enabled LMS offers to provision.');
    }
    return $entries;
  }

  /** Validates the base plan used as the product and cadence source. */
  private function validateBasePlan(LmsOffer $offer, array $plan, Price $base_price, string $environment): array {
    $errors = [];
    if (($plan['status'] ?? '') !== 'ACTIVE') {
      $errors[] = sprintf('the %s standard base plan is not ACTIVE', $environment);
    }
    if (!empty($plan['quantity_supported'])) {
      $errors[] = sprintf('the %s standard base plan supports variable quantities', $environment);
    }
    $trials = array_filter($plan['billing_cycles'] ?? [], static fn(array $cycle): bool => ($cycle['tenure_type'] ?? '') === 'TRIAL');
    $regular = array_values(array_filter($plan['billing_cycles'] ?? [], static fn(array $cycle): bool => ($cycle['tenure_type'] ?? '') === 'REGULAR'));
    if ($trials || count($regular) !== 1) {
      $errors[] = sprintf('the %s standard base plan must have one REGULAR cycle and no TRIAL cycles', $environment);
      return $errors;
    }
    $expected_frequency = $this->frequency($offer);
    $frequency = $regular[0]['frequency'] ?? [];
    if (($frequency['interval_unit'] ?? '') !== $expected_frequency['interval_unit'] || (int) ($frequency['interval_count'] ?? 0) !== $expected_frequency['interval_count']) {
      $errors[] = sprintf('the %s standard base plan cadence does not match the offer', $environment);
    }
    if ((int) ($regular[0]['total_cycles'] ?? -1) !== 0) {
      $errors[] = sprintf('the %s standard base plan must continue indefinitely', $environment);
    }
    $remote_price = $regular[0]['pricing_scheme']['fixed_price'] ?? [];
    if (!isset($remote_price['value']) || empty($remote_price['currency_code'])) {
      $errors[] = sprintf('the %s standard base plan has no fixed regular price', $environment);
    }
    elseif ($remote_price['currency_code'] !== $base_price->getCurrencyCode() || Calculator::compare((string) $remote_price['value'], $base_price->getNumber()) !== 0) {
      $errors[] = sprintf('the %s standard base plan price does not match the Commerce variation', $environment);
    }
    if (empty($plan['product_id'])) {
      $errors[] = sprintf('the %s standard base plan has no product ID', $environment);
    }
    return $errors;
  }

  /** Creates a deterministic PayPal request body. */
  private function buildPayload(LmsOffer $offer, string $environment, string $product_id, Price $vip_price, array $standard_plan): array {
    $environment_label = $environment === 'live' ? 'Live' : 'Sandbox';
    $preferences = is_array($standard_plan['payment_preferences'] ?? NULL)
      ? $standard_plan['payment_preferences']
      : [];
    return [
      'product_id' => $product_id,
      'name' => mb_substr(sprintf('Commerce LMS - %s - Standard VIP - %s', $offer->id(), $environment_label), 0, 127),
      'description' => mb_substr(sprintf('Standard VIP-inclusive plan for LMS offer %s.', $offer->id()), 0, 127),
      'billing_cycles' => [
        [
          'frequency' => $this->frequency($offer),
          'tenure_type' => 'REGULAR',
          'sequence' => 1,
          'total_cycles' => 0,
          'pricing_scheme' => [
            'fixed_price' => [
              'value' => $vip_price->getNumber(),
              'currency_code' => $vip_price->getCurrencyCode(),
            ],
          ],
        ],
      ],
      'quantity_supported' => FALSE,
      'payment_preferences' => [
        'auto_bill_outstanding' => (bool) ($preferences['auto_bill_outstanding'] ?? TRUE),
        'setup_fee' => [
          'value' => '0',
          'currency_code' => $vip_price->getCurrencyCode(),
        ],
        'setup_fee_failure_action' => 'CONTINUE',
        'payment_failure_threshold' => (int) ($preferences['payment_failure_threshold'] ?? 1),
      ],
    ];
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

  /** Validates a configured, recovered, or newly created VIP plan. */
  private function validatePlan(array $entry, array $plan): array {
    return $this->planCatalog->validateStandardVipPlan(
      $entry['offer'],
      $plan,
      $entry['standard_plan'],
      $entry['vip_price'],
    );
  }

}
