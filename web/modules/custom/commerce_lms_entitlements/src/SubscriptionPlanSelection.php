<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\commerce_price\Price;

/** Immutable result of resolving one recurring checkout's PayPal plan. */
final readonly class SubscriptionPlanSelection {

  public function __construct(
    public string $offerId,
    public string $planId,
    public bool $vip,
    public ?string $campaignId = NULL,
    public ?string $promotionUuid = NULL,
    public ?string $couponUuid = NULL,
    public ?Price $introPrice = NULL,
    public int $introCycles = 0,
  ) {}

}
