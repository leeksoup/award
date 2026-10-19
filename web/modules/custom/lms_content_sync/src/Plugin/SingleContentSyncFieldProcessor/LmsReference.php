<?php

declare(strict_types=1);

namespace Drupal\lms_content_sync\Plugin\SingleContentSyncFieldProcessor;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\single_content_sync\Plugin\SingleContentSyncFieldProcessor\EntityReference;

/**
 * Preserves LMS reference metadata while syncing referenced content by UUID.
 *
 * @SingleContentSyncFieldProcessor(
 *   id = "lms_reference",
 *   label = @Translation("LMS reference field processor"),
 *   field_type = "lms_reference",
 * )
 */
final class LmsReference extends EntityReference {

  /**
   * {@inheritdoc}
   */
  public function exportFieldValue(FieldItemListInterface $field): array {
    $target_type = $field->getFieldDefinition()->getSetting('target_type');
    $storage = $this->entityTypeManager->getStorage($target_type);
    $values = [];

    // Load each item in field order and keep its lesson- or course-specific data.
    foreach ($field->getValue() as $delta => $item) {
      $target_id = $item['target_id'] ?? NULL;
      $referenced_entity = $target_id !== NULL ? $storage->load($target_id) : NULL;
      if (!$referenced_entity instanceof FieldableEntityInterface) {
        throw new \UnexpectedValueException(sprintf(
          'Cannot export %s %s: %s item %d references missing %s %s.',
          $field->getEntity()->getEntityTypeId(),
          $field->getEntity()->id(),
          $field->getName(),
          $delta,
          $target_type,
          (string) $target_id,
        ));
      }

      $referenced_entity = $this->entityRepository->getTranslationFromContext(
        $referenced_entity,
        $field->getEntity()->language()->getId(),
      );
      $values[] = [
        'entity' => $this->exporter->doExportToArray($referenced_entity),
        'data' => $item['data'] ?? [],
      ];
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function importFieldValue(FieldableEntityInterface $entity, string $fieldName, array $value): void {
    $target_type = $entity->getFieldDefinition($fieldName)->getSetting('target_type');
    $values = [];

    foreach ($value as $delta => $item) {
      $reference = $item['entity'] ?? NULL;
      if (!is_array($reference)
        || ($reference['entity_type'] ?? NULL) !== $target_type
        || empty($reference['uuid'])
        || !is_array($item['data'] ?? NULL)) {
        throw new \UnexpectedValueException(sprintf(
          'Invalid %s item %d in %s import. Re-export it with LMS Content Sync enabled.',
          $fieldName,
          $delta,
          $entity->getEntityTypeId(),
        ));
      }

      if ($this->importer->isFullEntity($reference)) {
        $referenced_entity = $this->importer->doImport($reference);
      }
      else {
        $referenced_entity = $this->entityRepository->loadEntityByUuid($target_type, $reference['uuid']);
      }

      if (!$referenced_entity || !$referenced_entity->id()) {
        throw new \UnexpectedValueException(sprintf(
          'Cannot import %s item %d: %s %s is missing.',
          $fieldName,
          $delta,
          $target_type,
          $reference['uuid'],
        ));
      }

      $values[] = [
        'target_id' => $referenced_entity->id(),
        'data' => $item['data'],
      ];
    }

    $entity->set($fieldName, $values);
  }

}
