<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Entity;

use Drupal\commerce_price\Price;
use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines a curated introductory campaign for PayPal subscriptions.
 *
 * @ConfigEntityType(
 *   id = "commerce_lms_campaign",
 *   label = @Translation("Commerce LMS subscription campaign"),
 *   handlers = {
 *     "list_builder" = "Drupal\commerce_lms_entitlements\LmsSubscriptionCampaignListBuilder",
 *     "form" = {
 *       "add" = "Drupal\commerce_lms_entitlements\Form\SubscriptionCampaignForm",
 *       "edit" = "Drupal\commerce_lms_entitlements\Form\SubscriptionCampaignForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     }
 *   },
 *   config_prefix = "campaign",
 *   admin_permission = "administer commerce lms subscription campaigns",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "status",
 *     "behavior",
 *     "terms",
 *     "offer_mappings"
 *   },
 *   links = {
 *     "collection" = "/admin/commerce/config/lms-subscription-campaigns",
 *     "add-form" = "/admin/commerce/config/lms-subscription-campaigns/add",
 *     "edit-form" = "/admin/commerce/config/lms-subscription-campaigns/{commerce_lms_campaign}",
 *     "delete-form" = "/admin/commerce/config/lms-subscription-campaigns/{commerce_lms_campaign}/delete"
 *   }
 * )
 */
final class LmsSubscriptionCampaign extends ConfigEntityBase {

  public const ENTITY_TYPE_ID = 'commerce_lms_campaign';

  public const BEHAVIOR_FREE_VIP_LAUNCH = 'free_vip_launch';

  public const BEHAVIOR_INTRO_DISCOUNT = 'intro_discount';

  protected string $id;

  protected string $label;

  protected string $behavior = self::BEHAVIOR_INTRO_DISCOUNT;

  protected string $terms = '';

  /**
   * Offer-specific prices, billing cycles, and PayPal plan mappings.
   *
   * @var array<string, array<string, mixed>>
   */
  protected array $offer_mappings = [];

  public function getBehavior(): string {
    return $this->behavior;
  }

  public function getTerms(): string {
    return $this->terms;
  }

  public function forcesVip(): bool {
    return $this->behavior === self::BEHAVIOR_FREE_VIP_LAUNCH;
  }

  /**
   * Returns all mappings keyed by LMS offer ID.
   *
   * @return array<string, array<string, mixed>>
   *   The campaign's normalized offer mappings.
   */
  public function getOfferMappings(): array {
    return $this->offer_mappings;
  }

  /** Returns one offer mapping, or NULL when the offer is ineligible. */
  public function getOfferMapping(string $offer_id): ?array {
    return isset($this->offer_mappings[$offer_id]) && is_array($this->offer_mappings[$offer_id])
      ? $this->offer_mappings[$offer_id]
      : NULL;
  }

  /** Returns the introductory number of payments for an eligible offer. */
  public function getIntroCycles(string $offer_id): int {
    return (int) ($this->getOfferMapping($offer_id)['intro_cycles'] ?? 0);
  }

  /** Returns the configured introductory price for the selected tier. */
  public function getIntroPrice(string $offer_id, bool $vip): ?Price {
    $mapping = $this->getOfferMapping($offer_id);
    if (!$mapping) {
      return NULL;
    }
    $prefix = $vip ? 'vip' : 'base';
    $number = trim((string) ($mapping[$prefix . '_intro_number'] ?? ''));
    $currency = strtoupper(trim((string) ($mapping[$prefix . '_intro_currency'] ?? '')));
    return $number !== '' && is_numeric($number) && preg_match('/^[A-Z]{3}$/', $currency)
      ? new Price($number, $currency)
      : NULL;
  }

  /** Returns the campaign plan for an environment and selected tier. */
  public function getPayPalPlanId(string $offer_id, string $environment, bool $vip): string {
    $mapping = $this->getOfferMapping($offer_id);
    if (!$mapping) {
      return '';
    }
    $environment = $environment === 'live' ? 'live' : 'sandbox';
    $tier = $vip ? 'vip' : 'base';
    return trim((string) ($mapping['paypal_' . $environment . '_' . $tier . '_plan_id'] ?? ''));
  }

}
