<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\commerce_lms_entitlements\Entity\LmsSubscriptionCampaign;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/** Builds the administrative list of subscription campaigns. */
final class LmsSubscriptionCampaignListBuilder extends ConfigEntityListBuilder {

  /** {@inheritdoc} */
  public function buildHeader(): array {
    $header['label'] = $this->t('Name');
    $header['id'] = $this->t('Machine name');
    $header['behavior'] = $this->t('Behavior');
    $header['offers'] = $this->t('Eligible offers');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /** {@inheritdoc} */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof LmsSubscriptionCampaign);
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['behavior'] = $entity->forcesVip()
      ? $this->t('Launch: VIP included')
      : $this->t('Introductory discount');
    $row['offers'] = implode(', ', array_keys($entity->getOfferMappings()));
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

}
