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
 *     "list_builder" = "Drupal\\Core\\Config\\Entity\\ConfigEntityListBuilder",
 *     "form" = {"add" = "Drupal\\commerce_lms_entitlements\\Form\\OfferForm", "edit" = "Drupal\\commerce_lms_entitlements\\Form\\OfferForm", "delete" = "Drupal\\Core\\Entity\\EntityDeleteForm"}
 *   },
 *   config_prefix = "offer",
 *   admin_permission = "administer commerce lms offers",
 *   entity_keys = {"id" = "id", "label" = "label"},
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
  protected array $course_class_map = [];

  public function getVariationId(): int { return $this->variation_id; }
  public function getPurchaseType(): string { return $this->purchase_type; }
  public function getPaymentGatewayId(): string { return $this->payment_gateway_id; }
  public function getPayPalPlanId(): string { return $this->paypal_plan_id; }
  public function getCourseClassMap(): array { return $this->course_class_map; }
}
