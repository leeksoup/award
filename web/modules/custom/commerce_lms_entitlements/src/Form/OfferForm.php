<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Form;

use Drupal\commerce_lms_entitlements\Entity\LmsOffer;
use Drupal\commerce_lms_entitlements\PayPalPlanCatalog;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrative editor for Commerce, PayPal, and LMS offer mappings.
 *
 * Sandbox IDs remain manually managed for testing. Live plans are discovered
 * through the selected gateway and validated against the Commerce variation
 * before the offer can be saved.
 */
final class OfferForm extends EntityForm implements ContainerInjectionInterface {

  private PayPalPlanCatalog $planCatalog;

  /** {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->planCatalog = $container->get('commerce_lms_entitlements.paypal_plan_catalog');
    return $instance;
  }

  /** {@inheritdoc} */
  public function form(array $form, FormStateInterface $form_state): array {
    assert($this->entity instanceof LmsOffer);
    $offer = $this->entity;
    $lines = array_map(
      static fn(array $item): string => $item['course_id'] . ':' . $item['class_id'],
      $offer->getCourseClassMap(),
    );

    $gateway_options = [];
    $sandbox_gateway_options = [];
    $live_gateway_options = [];
    foreach (PaymentGateway::loadMultiple() as $gateway) {
      $label = $gateway->status()
        ? $this->t('@label (@id)', ['@label' => $gateway->label(), '@id' => $gateway->id()])
        : $this->t('@label (@id, disabled)', ['@label' => $gateway->label(), '@id' => $gateway->id()]);
      $gateway_options[$gateway->id()] = $label;
      if ($gateway->getPluginId() !== 'paypal_checkout_subscriptions') {
        continue;
      }
      if ($gateway->getPlugin()->getMode() === 'live') {
        $live_gateway_options[$gateway->id()] = $label;
      }
      else {
        $sandbox_gateway_options[$gateway->id()] = $label;
      }
    }

    $recurring_states = [
      'visible' => [':input[name="purchase_type"]' => ['value' => 'recurring']],
    ];
    $lifetime_states = [
      'visible' => [':input[name="purchase_type"]' => ['value' => 'lifetime']],
    ];

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $offer->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $offer->id(),
      '#machine_name' => ['exists' => [LmsOffer::class, 'load']],
      '#disabled' => !$offer->isNew(),
    ];
    $form['variation_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Commerce variation ID'),
      '#default_value' => $offer->getVariationId(),
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['purchase_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Purchase type'),
      '#options' => [
        'recurring' => $this->t('Recurring'),
        'lifetime' => $this->t('Lifetime one-time purchase'),
      ],
      '#default_value' => $offer->getPurchaseType(),
      '#required' => TRUE,
    ];

    $form['payment_gateway_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Lifetime payment gateway'),
      '#description' => $this->t('The only Commerce gateway allowed for a lifetime offer.'),
      '#options' => $gateway_options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $offer->getPaymentGatewayId(),
      '#states' => $lifetime_states,
    ];

    $form['paypal'] = [
      '#type' => 'details',
      '#title' => $this->t('PayPal subscription mappings'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#states' => $recurring_states,
    ];
    $form['paypal']['paypal_environment'] = [
      '#type' => 'radios',
      '#title' => $this->t('Active checkout environment'),
      '#description' => $this->t('Checkout permits only the gateway and plan mapped to this environment.'),
      '#options' => [
        'sandbox' => $this->t('Sandbox'),
        'live' => $this->t('Live'),
      ],
      '#default_value' => $offer->getPayPalEnvironment(),
    ];
    $form['paypal']['billing_interval'] = [
      '#type' => 'radios',
      '#title' => $this->t('Expected billing interval'),
      '#options' => [
        'monthly' => $this->t('Monthly'),
        'quarterly' => $this->t('Quarterly'),
        'annual' => $this->t('Annual'),
      ],
      '#default_value' => $offer->getBillingInterval(),
    ];

    $form['paypal']['sandbox'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Sandbox mapping'),
    ];
    $form['paypal']['sandbox']['paypal_sandbox_gateway_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Sandbox subscription gateway'),
      '#options' => $sandbox_gateway_options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $offer->getPayPalSandboxGatewayId(),
    ];
    $form['paypal']['sandbox']['paypal_sandbox_product_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sandbox product ID'),
      '#description' => $this->t('Optional reference; sandbox discovery is intentionally manual.'),
      '#default_value' => $offer->getPayPalSandboxProductId(),
    ];
    $form['paypal']['sandbox']['paypal_sandbox_plan_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sandbox plan ID'),
      '#default_value' => $offer->getPayPalSandboxPlanId(),
    ];

    $form['paypal']['live'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Live mapping'),
    ];
    $form['paypal']['live']['paypal_live_gateway_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Live subscription gateway'),
      '#options' => $live_gateway_options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $offer->getPayPalLiveGatewayId(),
    ];

    $live_plan_options = $form_state->get('commerce_lms_live_plan_options') ?: [];
    $saved_live_plan = $offer->getPayPalLivePlanId();
    if (!$live_plan_options && $saved_live_plan !== '') {
      $live_plan_options[$saved_live_plan] = $this->t('@id (saved mapping; load plans to refresh)', [
        '@id' => $saved_live_plan,
      ]);
    }
    $form['paypal']['live']['load_live_plans'] = [
      '#type' => 'submit',
      '#value' => $this->t('Load live plans from PayPal'),
      '#submit' => ['::loadLivePlans'],
      '#limit_validation_errors' => [
        ['paypal', 'live', 'paypal_live_gateway_id'],
      ],
    ];
    $form['paypal']['live']['paypal_live_plan_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Live PayPal plan'),
      '#description' => $this->t('Load plans after selecting the live gateway. Saving re-fetches and validates the selected plan.'),
      '#options' => $live_plan_options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $saved_live_plan,
    ];
    $form['paypal']['live']['paypal_live_product_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live product ID'),
      '#description' => $this->t('Filled automatically from the validated PayPal plan.'),
      '#default_value' => $offer->getPayPalLiveProductId(),
      '#disabled' => TRUE,
    ];

    $form['course_class_map'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Course to Class mapping'),
      '#description' => $this->t('One administrator-selected target per line as COURSE_ID:CLASS_ID. The Class must be a child of the Course.'),
      '#default_value' => implode("\n", $lines),
      '#required' => TRUE,
    ];

    return parent::form($form, $form_state);
  }

  /** Loads live product/plan choices and rebuilds the offer form. */
  public function loadLivePlans(array &$form, FormStateInterface $form_state): void {
    $gateway_id = trim((string) $form_state->getValue([
      'paypal',
      'live',
      'paypal_live_gateway_id',
    ]));
    try {
      $options = $this->planCatalog->livePlanOptions($gateway_id);
      $form_state->set('commerce_lms_live_plan_options', $options);
      if (!$options) {
        $this->messenger()->addWarning($this->t('PayPal returned no live billing plans.'));
      }
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Unable to load live PayPal plans: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
    $form_state->setRebuild();
  }

  /** {@inheritdoc} */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $paypal = $values['paypal'] ?? [];
    $sandbox = $paypal['sandbox'] ?? [];
    $live = $paypal['live'] ?? [];
    $map = $this->parseCourseClassMap((string) ($values['course_class_map'] ?? ''));

    $id = trim((string) ($values['id'] ?? ''));
    if ($id !== '') {
      $entity->set('id', $id);
    }
    $entity->set('label', trim((string) ($values['label'] ?? '')));
    $entity->set('variation_id', (int) ($values['variation_id'] ?? 0));
    $entity->set('purchase_type', (string) ($values['purchase_type'] ?? 'recurring'));
    $entity->set('payment_gateway_id', trim((string) ($values['payment_gateway_id'] ?? '')));
    $entity->set('paypal_environment', (string) ($paypal['paypal_environment'] ?? 'sandbox'));
    $entity->set('billing_interval', (string) ($paypal['billing_interval'] ?? 'monthly'));
    $entity->set('paypal_sandbox_gateway_id', trim((string) ($sandbox['paypal_sandbox_gateway_id'] ?? '')));
    $entity->set('paypal_sandbox_product_id', trim((string) ($sandbox['paypal_sandbox_product_id'] ?? '')));
    $entity->set('paypal_sandbox_plan_id', trim((string) ($sandbox['paypal_sandbox_plan_id'] ?? '')));
    $entity->set('paypal_live_gateway_id', trim((string) ($live['paypal_live_gateway_id'] ?? '')));
    $entity->set('paypal_live_product_id', trim((string) ($live['paypal_live_product_id'] ?? $entity->get('paypal_live_product_id'))));
    $entity->set('paypal_live_plan_id', trim((string) ($live['paypal_live_plan_id'] ?? '')));
    $entity->set('course_class_map', $map);
  }

  /** {@inheritdoc} */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    assert($this->entity instanceof LmsOffer);
    $offer = $this->entity;

    $input = trim((string) $form_state->getValue('course_class_map'));
    if ($input === '' || count($this->parseCourseClassMap($input)) !== count(preg_split('/\R/', $input) ?: [])) {
      $form_state->setErrorByName('course_class_map', $this->t('Each target must use COURSE_ID:CLASS_ID.'));
    }

    if ($offer->getPurchaseType() === 'lifetime') {
      $gateway = PaymentGateway::load($offer->getPaymentGatewayId());
      if (!$gateway) {
        $form_state->setErrorByName('payment_gateway_id', $this->t('Select an existing lifetime payment gateway.'));
      }
      return;
    }

    $active_environment = $offer->getPayPalEnvironment();
    $active_gateway_id = $offer->getActivePayPalGatewayId();
    $active_plan_id = $offer->getActivePayPalPlanId();
    if ($active_gateway_id === '' || $active_plan_id === '') {
      $form_state->setErrorByName('paypal', $this->t('The active @environment environment requires both a subscription gateway and plan.', [
        '@environment' => $active_environment,
      ]));
    }
    else {
      try {
        $this->planCatalog->loadGateway($active_gateway_id, $active_environment);
      }
      catch (\DomainException $e) {
        $form_state->setErrorByName('paypal', $e->getMessage());
      }
    }

    $has_live_mapping = $offer->getPayPalLiveGatewayId() !== '' || $offer->getPayPalLivePlanId() !== '';
    if ($has_live_mapping) {
      if ($offer->getPayPalLiveGatewayId() === '' || $offer->getPayPalLivePlanId() === '') {
        $form_state->setError($form['paypal']['live'], $this->t('A live mapping requires both its gateway and plan.'));
      }
      else {
        $result = $this->planCatalog->validateLiveOffer($offer);
        foreach ($result['errors'] as $error) {
          $form_state->setError($form['paypal']['live']['paypal_live_plan_id'], $error);
        }
        if (!$result['errors'] && !empty($result['plan']['product_id'])) {
          $offer->set('paypal_live_product_id', (string) $result['plan']['product_id']);
        }
      }
    }
  }

  /** {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Legacy fields remain populated during the transition so exported config
    // and older code can still identify the currently active recurring pair.
    if ($this->entity instanceof LmsOffer && $this->entity->getPurchaseType() === 'recurring') {
      $this->entity->set('payment_gateway_id', $this->entity->getActivePayPalGatewayId());
      $this->entity->set('paypal_plan_id', $this->entity->getActivePayPalPlanId());
    }
    parent::submitForm($form, $form_state);
  }

  /** Parses valid COURSE_ID:CLASS_ID rows without assigning raw textarea data. */
  private function parseCourseClassMap(string $input): array {
    $map = [];
    foreach (preg_split('/\R/', trim($input)) ?: [] as $line) {
      if (preg_match('/^\s*(\d+)\s*:\s*(\d+)\s*$/', $line, $matches)) {
        $map[] = [
          'course_id' => (int) $matches[1],
          'class_id' => (int) $matches[2],
        ];
      }
    }
    return $map;
  }

}
