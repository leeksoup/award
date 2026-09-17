<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Form;

use Drupal\commerce_lms_entitlements\Entity\LmsOffer;
use Drupal\commerce_lms_entitlements\Entity\LmsSubscriptionCampaign;
use Drupal\commerce_lms_entitlements\PayPalPlanCatalog;
use Drupal\commerce_price\Calculator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Administrative form for curated subscription campaigns. */
final class SubscriptionCampaignForm extends EntityForm implements ContainerInjectionInterface {

  protected PayPalPlanCatalog $planCatalog;

  /** {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->planCatalog = $container->get('commerce_lms_entitlements.paypal_plan_catalog');
    return $instance;
  }

  /** {@inheritdoc} */
  public function form(array $form, FormStateInterface $form_state): array {
    assert($this->entity instanceof LmsSubscriptionCampaign);
    $campaign = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $campaign->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $campaign->id(),
      '#machine_name' => ['exists' => [LmsSubscriptionCampaign::class, 'load']],
      '#disabled' => !$campaign->isNew(),
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $campaign->status(),
      '#description' => $this->t('Disabled campaigns cannot be used during checkout.'),
    ];
    $form['behavior'] = [
      '#type' => 'radios',
      '#title' => $this->t('Campaign behavior'),
      '#options' => [
        LmsSubscriptionCampaign::BEHAVIOR_FREE_VIP_LAUNCH => $this->t('Launch campaign: include VIP at no added introductory cost'),
        LmsSubscriptionCampaign::BEHAVIOR_INTRO_DISCOUNT => $this->t('Introductory discount: preserve the customer-selected tier'),
      ],
      '#default_value' => $campaign->getBehavior(),
      '#required' => TRUE,
    ];
    $form['terms'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Customer-facing terms'),
      '#default_value' => $campaign->getTerms(),
      '#required' => TRUE,
      '#description' => $this->t('Displayed with the calculated introductory and renewal schedule during checkout.'),
    ];

    $form['offer_mappings'] = [
      '#type' => 'details',
      '#title' => $this->t('Eligible subscription offers'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $offers = $this->entityTypeManager->getStorage('commerce_lms_offer')->loadMultiple();
    uasort($offers, static fn(LmsOffer $a, LmsOffer $b): int => strnatcasecmp((string) $a->label(), (string) $b->label()));
    foreach ($offers as $offer) {
      if (!$offer instanceof LmsOffer || $offer->getPurchaseType() !== 'recurring') {
        continue;
      }
      $offer_id = $offer->id();
      $mapping = $campaign->getOfferMapping($offer_id) ?? [];
      $form['offer_mappings'][$offer_id] = [
        '#type' => 'details',
        '#title' => $this->t('@label (@id, @interval)', [
          '@label' => $offer->label(),
          '@id' => $offer_id,
          '@interval' => $offer->getBillingInterval(),
        ]),
        '#open' => isset($campaign->getOfferMappings()[$offer_id]),
      ];
      $form['offer_mappings'][$offer_id]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Include this offer in the campaign'),
        '#default_value' => isset($campaign->getOfferMappings()[$offer_id]),
      ];
      $form['offer_mappings'][$offer_id]['intro_cycles'] = [
        '#type' => 'number',
        '#title' => $this->t('Introductory payments'),
        '#default_value' => $mapping['intro_cycles'] ?? 1,
        '#min' => 1,
        '#max' => 999,
      ];
      $this->addPriceElements($form['offer_mappings'][$offer_id], 'base', $mapping, $this->t('Base introductory price'));
      $this->addPriceElements($form['offer_mappings'][$offer_id], 'vip', $mapping, $this->t('VIP introductory price'));
      foreach (['sandbox' => $this->t('Sandbox'), 'live' => $this->t('Live')] as $environment => $label) {
        $form['offer_mappings'][$offer_id][$environment] = [
          '#type' => 'fieldset',
          '#title' => $label,
        ];
        foreach (['base' => $this->t('Base campaign plan ID'), 'vip' => $this->t('VIP campaign plan ID')] as $tier => $title) {
          $key = 'paypal_' . $environment . '_' . $tier . '_plan_id';
          $form['offer_mappings'][$offer_id][$environment][$key] = [
            '#type' => 'textfield',
            '#title' => $title,
            '#default_value' => $mapping[$key] ?? '',
          ];
        }
      }
    }

    return parent::form($form, $form_state);
  }

  /** Adds one amount and ISO currency pair to an offer mapping. */
  private function addPriceElements(array &$element, string $tier, array $mapping, mixed $title): void {
    $element[$tier . '_price'] = [
      '#type' => 'fieldset',
      '#title' => $title,
    ];
    $element[$tier . '_price'][$tier . '_intro_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount'),
      '#default_value' => $mapping[$tier . '_intro_number'] ?? '',
    ];
    $element[$tier . '_price'][$tier . '_intro_currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency'),
      '#default_value' => $mapping[$tier . '_intro_currency'] ?? 'USD',
      '#maxlength' => 3,
      '#size' => 5,
    ];
  }

  /** {@inheritdoc} */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $id = trim((string) ($values['id'] ?? ''));
    if ($id !== '') {
      $entity->set('id', $id);
    }
    $entity->set('label', trim((string) ($values['label'] ?? '')));
    $entity->setStatus(!empty($values['status']));
    $entity->set('behavior', (string) ($values['behavior'] ?? LmsSubscriptionCampaign::BEHAVIOR_INTRO_DISCOUNT));
    $entity->set('terms', trim((string) ($values['terms'] ?? '')));

    $mappings = [];
    foreach (($values['offer_mappings'] ?? []) as $offer_id => $input) {
      if (empty($input['enabled'])) {
        continue;
      }
      $base = $input['base_price'] ?? [];
      $vip = $input['vip_price'] ?? [];
      $sandbox = $input['sandbox'] ?? [];
      $live = $input['live'] ?? [];
      $mappings[(string) $offer_id] = [
        'intro_cycles' => (int) ($input['intro_cycles'] ?? 0),
        'base_intro_number' => trim((string) ($base['base_intro_number'] ?? '')),
        'base_intro_currency' => strtoupper(trim((string) ($base['base_intro_currency'] ?? ''))),
        'vip_intro_number' => trim((string) ($vip['vip_intro_number'] ?? '')),
        'vip_intro_currency' => strtoupper(trim((string) ($vip['vip_intro_currency'] ?? ''))),
        'paypal_sandbox_base_plan_id' => trim((string) ($sandbox['paypal_sandbox_base_plan_id'] ?? '')),
        'paypal_sandbox_vip_plan_id' => trim((string) ($sandbox['paypal_sandbox_vip_plan_id'] ?? '')),
        'paypal_live_base_plan_id' => trim((string) ($live['paypal_live_base_plan_id'] ?? '')),
        'paypal_live_vip_plan_id' => trim((string) ($live['paypal_live_vip_plan_id'] ?? '')),
      ];
    }
    $entity->set('offer_mappings', $mappings);
  }

  /** {@inheritdoc} */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    assert($this->entity instanceof LmsSubscriptionCampaign);
    $campaign = $this->entity;
    $enabled = !empty($form_state->getValue('status'));
    if (!$campaign->getOfferMappings()) {
      $form_state->setErrorByName('offer_mappings', $this->t('Select at least one recurring offer.'));
      return;
    }

    foreach ($campaign->getOfferMappings() as $offer_id => $mapping) {
      $offer = $this->entityTypeManager->getStorage('commerce_lms_offer')->load($offer_id);
      if (!$offer instanceof LmsOffer || $offer->getPurchaseType() !== 'recurring') {
        $form_state->setErrorByName('offer_mappings', $this->t('Offer @offer is missing or is not recurring.', ['@offer' => $offer_id]));
        continue;
      }
      $cycles = (int) ($mapping['intro_cycles'] ?? 0);
      if ($cycles < 1 || $cycles > 999) {
        $form_state->setErrorByName('offer_mappings][' . $offer_id . '][intro_cycles', $this->t('Introductory payments must be between 1 and 999.'));
      }
      if ($offer->isVipEnabled() || $campaign->forcesVip()) {
        $this->validatePrice($form_state, $offer_id, 'vip', $campaign->getIntroPrice($offer_id, TRUE));
      }
      if (!$campaign->forcesVip()) {
        $this->validatePrice($form_state, $offer_id, 'base', $campaign->getIntroPrice($offer_id, FALSE));
      }
      if ($campaign->forcesVip() && !$offer->isVipEnabled()) {
        $form_state->setErrorByName('offer_mappings', $this->t('Launch campaign offer @offer must have VIP enabled.', ['@offer' => $offer_id]));
      }
      if ($enabled) {
        $environment = $offer->getPayPalEnvironment();
        if (($offer->isVipEnabled() || $campaign->forcesVip()) && $campaign->getPayPalPlanId($offer_id, $environment, TRUE) === '') {
          $form_state->setErrorByName('offer_mappings', $this->t('Offer @offer requires an active-environment VIP campaign plan.', ['@offer' => $offer_id]));
        }
        if (!$campaign->forcesVip() && $campaign->getPayPalPlanId($offer_id, $environment, FALSE) === '') {
          $form_state->setErrorByName('offer_mappings', $this->t('Offer @offer requires an active-environment base campaign plan.', ['@offer' => $offer_id]));
        }
      }

      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($offer->getVariationId());
      $base_price = $variation?->getPrice();
      if (!$base_price) {
        $form_state->setErrorByName('offer_mappings', $this->t('Offer @offer has no Commerce variation price.', ['@offer' => $offer_id]));
        continue;
      }
      if ($campaign->forcesVip()) {
        if (!$campaign->hasValidLaunchIntroPrice($offer_id, $base_price)) {
          $form_state->setErrorByName('offer_mappings', $this->t('Launch offer @offer must use the variation currency and may not charge more than its normal base price during the VIP introductory period.', ['@offer' => $offer_id]));
        }
      }
      else {
        foreach ([FALSE, TRUE] as $vip) {
          if ($vip && !$offer->isVipEnabled()) {
            continue;
          }
          $intro_price = $campaign->getIntroPrice($offer_id, $vip);
          if (!$intro_price) {
            continue;
          }
          if ($vip && $offer->getVipSurchargeCurrency() !== $base_price->getCurrencyCode()) {
            $form_state->setErrorByName('offer_mappings', $this->t('Offer @offer has mismatched variation and VIP currencies.', ['@offer' => $offer_id]));
            continue;
          }
          $regular_price = $vip
            ? $base_price->add(new \Drupal\commerce_price\Price($offer->getVipSurchargeNumber(), $offer->getVipSurchargeCurrency()))
            : $base_price;
          if ($regular_price->getCurrencyCode() !== $intro_price->getCurrencyCode() || !$regular_price->greaterThan($intro_price)) {
            $form_state->setErrorByName('offer_mappings', $this->t('Offer @offer requires the @tier introductory price to be lower than its regular price.', [
              '@offer' => $offer_id,
              '@tier' => $vip ? 'VIP' : 'base',
            ]));
          }
        }
      }
    }
    if ($enabled && !$form_state->getErrors()) {
      foreach ($this->planCatalog->validateCampaign($campaign) as $error) {
        $form_state->setErrorByName('offer_mappings', $error);
      }
    }
  }

  /** Validates one configured introductory price. */
  private function validatePrice(FormStateInterface $form_state, string $offer_id, string $tier, mixed $price): void {
    if (!$price || !is_numeric($price->getNumber()) || (float) $price->getNumber() < 0 || !preg_match('/^[A-Z]{3}$/', $price->getCurrencyCode())) {
      $form_state->setErrorByName('offer_mappings', $this->t('Offer @offer requires a non-negative @tier introductory price and three-letter currency.', [
        '@offer' => $offer_id,
        '@tier' => $tier,
      ]));
    }
  }

}
