<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\commerce_lms_entitlements\Entity\LmsOffer;

/**
 * Builds the administrative table of Commerce LMS offers.
 *
 * The core entity list builder only guarantees an operations column. This
 * implementation exposes the fields administrators need to audit the mapping
 * between a Commerce variation, its payment flow, and its LMS access targets.
 */
final class LmsOfferListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Name');
    $header['id'] = $this->t('Machine name');
    $header['variation_id'] = $this->t('Variation ID');
    $header['purchase_type'] = $this->t('Purchase type');
    $header['payment_gateway_id'] = $this->t('Active gateway');
    $header['paypal_plan_id'] = $this->t('Active PayPal plan');
    $header['paypal_mappings'] = $this->t('Other PayPal mapping');
    $header['course_class_map'] = $this->t('Course/Class targets');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    assert($entity instanceof LmsOffer);

    $targets = array_map(
      static fn(array $target): string => $target['course_id'] . ':' . $target['class_id'],
      $entity->getCourseClassMap(),
    );

    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['variation_id'] = $entity->getVariationId();
    $row['purchase_type'] = $entity->getPurchaseType() === 'recurring'
      ? $this->t('Recurring')
      : $this->t('Lifetime');
    if ($entity->getPurchaseType() === 'recurring') {
      $environment = $entity->getPayPalEnvironment();
      $other_environment = $environment === 'live' ? 'sandbox' : 'live';
      $row['payment_gateway_id'] = $environment . ': ' . $entity->getActivePayPalGatewayId();
      $row['paypal_plan_id'] = $entity->getActivePayPalPlanId() ?: $this->t('—');
      $other_gateway = $other_environment === 'live'
        ? $entity->getPayPalLiveGatewayId()
        : $entity->getPayPalSandboxGatewayId();
      $other_plan = $other_environment === 'live'
        ? $entity->getPayPalLivePlanId()
        : $entity->getPayPalSandboxPlanId();
      $row['paypal_mappings'] = $other_gateway && $other_plan
        ? $other_environment . ': ' . $other_gateway . ' / ' . $other_plan
        : $this->t('—');
    }
    else {
      $row['payment_gateway_id'] = $entity->getPaymentGatewayId();
      $row['paypal_plan_id'] = $this->t('—');
      $row['paypal_mappings'] = $this->t('—');
    }
    $row['course_class_map'] = implode(', ', $targets);

    return $row + parent::buildRow($entity);
  }

}
