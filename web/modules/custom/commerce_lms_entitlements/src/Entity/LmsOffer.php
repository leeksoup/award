<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Maps one sellable variation to a PayPal flow and administrator-selected Classes.
 *
 * @ConfigEntityType(
 *   id = "commerce_lms_offer",
 *   label = @Translation("Commerce LMS offer"),
 *   handlers = {
 *     "list_builder" = "Drupal\commerce_lms_entitlements\LmsOfferListBuilder",
 *     "form" = {"add" = "Drupal\commerce_lms_entitlements\Form\OfferForm", "edit" = "Drupal\commerce_lms_entitlements\Form\OfferForm", "delete" = "Drupal\Core\Entity\EntityDeleteForm"}
 *   },
 *   config_prefix = "offer",
 *   admin_permission = "administer commerce lms offers",
 *   entity_keys = {"id" = "id", "label" = "label"},
 *   config_export = {
 *     "id",
 *     "label",
 *     "variation_id",
 *     "purchase_type",
 *     "payment_gateway_id",
 *     "paypal_plan_id",
 *     "paypal_environment",
 *     "paypal_sandbox_gateway_id",
 *     "paypal_sandbox_product_id",
 *     "paypal_sandbox_plan_id",
 *     "paypal_live_gateway_id",
 *     "paypal_live_product_id",
 *     "paypal_live_plan_id",
 *     "billing_interval",
 *     "vip_enabled",
 *     "vip_surcharge_number",
 *     "vip_surcharge_currency",
 *     "paypal_sandbox_vip_plan_id",
 *     "paypal_live_vip_plan_id",
 *     "course_class_map"
 *   },
 *   links = {
 *     "collection" = "/admin/commerce/config/lms-offers",
 *     "add-form" = "/admin/commerce/config/lms-offers/add",
 *     "edit-form" = "/admin/commerce/config/lms-offers/{commerce_lms_offer}",
 *     "delete-form" = "/admin/commerce/config/lms-offers/{commerce_lms_offer}/delete"
 *   }
 * )
 */
final class LmsOffer extends ConfigEntityBase {
  protected string $id;
  protected string $label;
  protected int $variation_id = 0;
  protected string $purchase_type = 'recurring';
  protected string $payment_gateway_id = '';
  protected string $paypal_plan_id = '';
  protected string $paypal_environment = 'sandbox';
  protected string $paypal_sandbox_gateway_id = '';
  protected string $paypal_sandbox_product_id = '';
  protected string $paypal_sandbox_plan_id = '';
  protected string $paypal_live_gateway_id = '';
  protected string $paypal_live_product_id = '';
  protected string $paypal_live_plan_id = '';
  protected string $billing_interval = 'monthly';
  protected bool $vip_enabled = FALSE;
  protected string $vip_surcharge_number = '0';
  protected string $vip_surcharge_currency = 'USD';
  protected string $paypal_sandbox_vip_plan_id = '';
  protected string $paypal_live_vip_plan_id = '';
  protected array $course_class_map = [];

  public function getVariationId(): int { return $this->variation_id; }
  public function getPurchaseType(): string { return $this->purchase_type; }
  public function getPaymentGatewayId(): string { return $this->payment_gateway_id; }
  public function getPayPalPlanId(): string { return $this->paypal_plan_id; }
  public function getPayPalEnvironment(): string { return $this->paypal_environment; }
  public function getPayPalSandboxGatewayId(): string { return $this->paypal_sandbox_gateway_id; }
  public function getPayPalSandboxProductId(): string { return $this->paypal_sandbox_product_id; }
  public function getPayPalSandboxPlanId(): string { return $this->paypal_sandbox_plan_id; }
  public function getPayPalLiveGatewayId(): string { return $this->paypal_live_gateway_id; }
  public function getPayPalLiveProductId(): string { return $this->paypal_live_product_id; }
  public function getPayPalLivePlanId(): string { return $this->paypal_live_plan_id; }
  public function getBillingInterval(): string { return $this->billing_interval; }
  public function isVipEnabled(): bool { return $this->vip_enabled; }
  public function getVipSurchargeNumber(): string { return $this->vip_surcharge_number; }
  public function getVipSurchargeCurrency(): string { return $this->vip_surcharge_currency; }
  public function getPayPalSandboxVipPlanId(): string { return $this->paypal_sandbox_vip_plan_id; }
  public function getPayPalLiveVipPlanId(): string { return $this->paypal_live_vip_plan_id; }

  /** Returns the gateway ID for the explicitly active recurring environment. */
  public function getActivePayPalGatewayId(): string {
    return $this->paypal_environment === 'live'
      ? $this->paypal_live_gateway_id
      : $this->paypal_sandbox_gateway_id;
  }

  /** Returns the plan ID for the explicitly active recurring environment. */
  public function getActivePayPalPlanId(): string {
    return $this->paypal_environment === 'live'
      ? $this->paypal_live_plan_id
      : $this->paypal_sandbox_plan_id;
  }

  /** Returns the VIP-inclusive plan for the active PayPal environment. */
  public function getActivePayPalVipPlanId(): string {
    return $this->paypal_environment === 'live'
      ? $this->paypal_live_vip_plan_id
      : $this->paypal_sandbox_vip_plan_id;
  }

  public function getCourseClassMap(): array { return $this->course_class_map; }
}
