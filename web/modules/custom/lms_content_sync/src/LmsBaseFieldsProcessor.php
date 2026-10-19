<?php

declare(strict_types=1);

namespace Drupal\lms_content_sync;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\single_content_sync\Plugin\SingleContentSyncBaseFieldsProcessor\GenericContentEntity;

/**
 * Exports LMS base fields with a scalar language code for Single Content Sync.
 */
abstract class LmsBaseFieldsProcessor extends GenericContentEntity {

  /**
   * {@inheritdoc}
   */
  public function exportBaseValues(FieldableEntityInterface $entity): array {
    $values = parent::exportBaseValues($entity);
    // The importer reads base_fields.langcode before invoking field processors.
    $values['langcode'] = $entity->language()->getId();
    return $values;
  }

}
