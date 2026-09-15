<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\Commerce\PromotionOffer;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\Entity\LmsSubscriptionCampaign;
use Drupal\commerce_lms_entitlements\SubscriptionCampaignResolver;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_promotion\Attribute\CommercePromotionOffer;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\commerce_promotion\Plugin\Commerce\PromotionOffer\OrderPromotionOfferBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Applies the exact introductory price selected by an LMS campaign. */
#[CommercePromotionOffer(
  id: SubscriptionCampaignResolver::OFFER_PLUGIN_ID,
  label: new TranslatableMarkup('LMS subscription campaign'),
  entity_type: 'commerce_order',
)]
final class LmsSubscriptionCampaignOffer extends OrderPromotionOfferBase {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected EntitlementManager $entitlementManager;

  protected SubscriptionCampaignResolver $campaignResolver;

  /** {@inheritdoc} */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entitlementManager = $container->get('commerce_lms_entitlements.manager');
    $instance->campaignResolver = $container->get('commerce_lms_entitlements.subscription_campaign_resolver');
    return $instance;
  }

  /** {@inheritdoc} */
  public function defaultConfiguration(): array {
    return ['campaign_id' => ''] + parent::defaultConfiguration();
  }

  /** {@inheritdoc} */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $options = [];
    foreach ($this->entityTypeManager->getStorage('commerce_lms_subscription_campaign')->loadMultiple() as $campaign) {
      if ($campaign instanceof LmsSubscriptionCampaign && $campaign->status()) {
        $options[$campaign->id()] = $campaign->label();
      }
    }
    $form['campaign_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Subscription campaign'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $this->configuration['campaign_id'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /** {@inheritdoc} */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $campaign_id = trim((string) ($values['campaign_id'] ?? ''));
    $campaign = $campaign_id !== ''
      ? $this->entityTypeManager->getStorage('commerce_lms_subscription_campaign')->load($campaign_id)
      : NULL;
    if (!$campaign instanceof LmsSubscriptionCampaign || !$campaign->status()) {
      $form_state->setErrorByName('campaign_id', $this->t('Select an enabled subscription campaign.'));
    }
  }

  /** {@inheritdoc} */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['campaign_id'] = trim((string) ($values['campaign_id'] ?? ''));
    }
  }

  /** {@inheritdoc} */
  public function apply(EntityInterface $entity, PromotionInterface $promotion): void {
    $this->assertEntity($entity);
    assert($entity instanceof OrderInterface);
    try {
      $lms_offer = $this->entitlementManager->offerForOrder($entity);
      if ($lms_offer->getPurchaseType() !== 'recurring') {
        return;
      }
      $context = $this->campaignResolver->resolve($entity, $lms_offer);
    }
    catch (\DomainException) {
      return;
    }
    if (!$context || $context['campaign']->id() !== $this->configuration['campaign_id']) {
      return;
    }

    $vip = $lms_offer->isVipEnabled() && (bool) $entity->getData('commerce_lms_vip_selected');
    $intro_price = $context['campaign']->getIntroPrice($lms_offer->id(), $vip);
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($lms_offer->getVariationId());
    $regular_price = $variation?->getPrice();
    if (!$intro_price || !$regular_price || $intro_price->getCurrencyCode() !== $regular_price->getCurrencyCode()) {
      return;
    }
    if ($vip) {
      if ($lms_offer->getVipSurchargeCurrency() !== $regular_price->getCurrencyCode()) {
        return;
      }
      $regular_price = $regular_price->add(new \Drupal\commerce_price\Price(
        $lms_offer->getVipSurchargeNumber(),
        $lms_offer->getVipSurchargeCurrency(),
      ));
    }
    if (!$regular_price->greaterThan($intro_price)) {
      return;
    }
    $discount = $regular_price->subtract($intro_price);
    $subtotal = $entity->getSubtotalPrice();
    if (!$subtotal || $discount->greaterThan($subtotal)) {
      return;
    }

    $amounts = $this->splitter->split($entity, $discount);
    foreach ($entity->getItems() as $order_item) {
      if (!isset($amounts[$order_item->id()])) {
        continue;
      }
      $order_item->addAdjustment(new Adjustment([
        'type' => 'promotion',
        'label' => $promotion->getDisplayName() ?: $this->t('Subscription introductory discount'),
        'amount' => $amounts[$order_item->id()]->multiply('-1'),
        'source_id' => $promotion->id(),
      ]));
    }
  }

}
