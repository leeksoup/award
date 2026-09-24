<?php
  use Drupal\commerce_lms_entitlements\Entity\LmsOffer;

  $offer_storage = \Drupal::entityTypeManager()
    ->getStorage("commerce_lms_offer");
  $variation_storage = \Drupal::entityTypeManager()
    ->getStorage("commerce_product_variation");

  foreach ($offer_storage->loadMultiple() as $offer) {
    if (
      !$offer instanceof LmsOffer ||
      $offer->getPurchaseType() !== "recurring"
    ) {
      continue;
    }

    $variation = $variation_storage->load($offer->getVariationId());
    $price = $variation?->getPrice();

    printf(
      "Offer: %s (%s)\n" .
      "  Environment: %s\n" .
      "  Interval: %s\n" .
      "  Variation: %d\n" .
      "  Base price: %s %s\n" .
      "  VIP enabled: %s\n" .
      "  VIP surcharge: %s %s\n" .
      "  Sandbox gateway: %s\n" .
      "  Sandbox product: %s\n" .
      "  Standard sandbox plan: %s\n" .
      "  Standard sandbox VIP plan: %s\n\n",
      $offer->label(),
      $offer->id(),
      $offer->getPayPalEnvironment(),
      $offer->getBillingInterval(),
      $offer->getVariationId(),
      $price?->getNumber() ?? "MISSING",
      $price?->getCurrencyCode() ?? "",
      $offer->isVipEnabled() ? "yes" : "no",
      $offer->getVipSurchargeNumber(),
      $offer->getVipSurchargeCurrency(),
      $offer->getPayPalSandboxGatewayId(),
      $offer->getPayPalSandboxProductId(),
      $offer->getPayPalSandboxPlanId(),
      $offer->getPayPalSandboxVipPlanId(),
    );
  }

