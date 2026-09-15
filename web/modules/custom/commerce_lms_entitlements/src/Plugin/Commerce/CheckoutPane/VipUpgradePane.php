<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Annotation\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\SubscriptionCampaignResolver;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Presents the optional VIP recurring upgrade before PayPal approval.
 *
 * @CommerceCheckoutPane(
 *   id = "commerce_lms_vip_upgrade",
 *   label = @Translation("VIP upgrade"),
 *   default_step = "order_information",
 *   weight = 25
 * )
 */
final class VipUpgradePane extends CheckoutPaneBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow, EntityTypeManagerInterface $entity_type_manager, private EntitlementManager $manager, private SubscriptionCampaignResolver $campaignResolver) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $checkout_flow, $entity_type_manager);
  }

  /** {@inheritdoc} */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL): static {
    return new static($configuration, $plugin_id, $plugin_definition, $checkout_flow, $container->get('entity_type.manager'), $container->get('commerce_lms_entitlements.manager'), $container->get('commerce_lms_entitlements.subscription_campaign_resolver'));
  }

  /** {@inheritdoc} */
  public function isVisible(): bool {
    try {
      $offer = $this->manager->offerForOrder($this->order);
      return $offer->getPurchaseType() === 'recurring' && $offer->isVipEnabled();
    }
    catch (\DomainException) {
      return FALSE;
    }
  }

  /** {@inheritdoc} */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $offer = $this->manager->offerForOrder($this->order);
    $force_vip = FALSE;
    try {
      $context = $this->campaignResolver->resolve($this->order, $offer);
      $force_vip = $context && $context['campaign']->forcesVip();
    }
    catch (\DomainException) {
      // The campaign pane reports invalid coupon configuration.
    }
    $pane_form['selected'] = [
      '#type' => 'checkbox',
      '#title' => $force_vip ? $this->t('VIP live-session upgrade included') : $this->t('Add the VIP live-session upgrade'),
      '#description' => $this->t('Adds @amount @currency each @interval and includes one live group session per calendar month.', [
        '@amount' => $offer->getVipSurchargeNumber(),
        '@currency' => $offer->getVipSurchargeCurrency(),
        '@interval' => $offer->getBillingInterval(),
      ]),
      '#default_value' => $force_vip || (bool) $this->order->getData('commerce_lms_vip_selected'),
      '#disabled' => $force_vip,
    ];
    return $pane_form;
  }

  /** {@inheritdoc} */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $values = $form_state->getValue($pane_form['#parents']);
    $offer = $this->manager->offerForOrder($this->order);
    $force_vip = FALSE;
    try {
      $context = $this->campaignResolver->resolve($this->order, $offer);
      $force_vip = $context && $context['campaign']->forcesVip();
    }
    catch (\DomainException) {
      // The campaign pane reports invalid coupon configuration.
    }
    $this->order->setData('commerce_lms_vip_selected', $force_vip || !empty($values['selected']));
    $this->order->save();
  }

}
