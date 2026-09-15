<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Attribute\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\SubscriptionCampaignResolver;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Validates and discloses a coupon-backed subscription campaign. */
#[CommerceCheckoutPane(
  id: 'commerce_lms_subscription_campaign',
  label: new TranslatableMarkup('Subscription offer'),
  default_step: 'order_information',
  weight: 24,
)]
final class SubscriptionCampaignPane extends CheckoutPaneBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    CheckoutFlowInterface $checkout_flow,
    EntityTypeManagerInterface $entity_type_manager,
    private EntitlementManager $entitlementManager,
    private SubscriptionCampaignResolver $campaignResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $checkout_flow, $entity_type_manager);
  }

  /** {@inheritdoc} */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $checkout_flow,
      $container->get('entity_type.manager'),
      $container->get('commerce_lms_entitlements.manager'),
      $container->get('commerce_lms_entitlements.subscription_campaign_resolver'),
    );
  }

  /** {@inheritdoc} */
  public function isVisible(): bool {
    try {
      $offer = $this->entitlementManager->offerForOrder($this->order);
      return $offer->getPurchaseType() === 'recurring'
        && $this->order->hasField('coupons')
        && !$this->order->get('coupons')->isEmpty();
    }
    catch (\DomainException) {
      return FALSE;
    }
  }

  /** {@inheritdoc} */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    try {
      $offer = $this->entitlementManager->offerForOrder($this->order);
      $context = $this->campaignResolver->resolve($this->order, $offer);
      if (!$context) {
        return $pane_form;
      }
      $campaign = $context['campaign'];
      $vip = $campaign->forcesVip() || ($offer->isVipEnabled() && (bool) $this->order->getData('commerce_lms_vip_selected'));
      $intro = $campaign->getIntroPrice($offer->id(), $vip);
      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($offer->getVariationId());
      $regular = $variation?->getPrice();
      if ($vip && $regular) {
        $regular = $regular->add(new \Drupal\commerce_price\Price($offer->getVipSurchargeNumber(), $offer->getVipSurchargeCurrency()));
      }
      $pane_form['summary'] = [
        '#type' => 'item',
        '#title' => $campaign->label(),
        '#markup' => $this->t('@intro for @cycles @interval payment(s), then @regular per payment. @vip', [
          '@intro' => $intro ? $intro->getNumber() . ' ' . $intro->getCurrencyCode() : $this->t('Invalid introductory price'),
          '@cycles' => $campaign->getIntroCycles($offer->id()),
          '@interval' => $offer->getBillingInterval(),
          '@regular' => $regular ? $regular->getNumber() . ' ' . $regular->getCurrencyCode() : $this->t('Invalid regular price'),
          '@vip' => $vip ? $this->t('VIP is included.') : $this->t('VIP is not included.'),
        ]),
      ];
      $pane_form['terms'] = [
        '#type' => 'item',
        '#title' => $this->t('Offer terms'),
        '#plain_text' => $campaign->getTerms(),
      ];
    }
    catch (\DomainException $e) {
      $pane_form['error'] = [
        '#type' => 'item',
        '#title' => $this->t('Coupon problem'),
        '#plain_text' => $e->getMessage(),
      ];
    }
    return $pane_form;
  }

  /** {@inheritdoc} */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    try {
      $offer = $this->entitlementManager->offerForOrder($this->order);
      $this->campaignResolver->selectPlan($this->order, $offer);
    }
    catch (\DomainException $e) {
      $form_state->setError($pane_form, $e->getMessage());
    }
  }

}
