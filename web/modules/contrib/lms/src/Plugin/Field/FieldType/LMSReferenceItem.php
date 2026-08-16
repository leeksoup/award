<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Field\Plugin\Field\FieldType\MapItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * Entity reference with additional data.
 */
#[FieldType(
  id: 'lms_reference',
  label: new TranslatableMarkup('LMS Entity reference'),
  description: new TranslatableMarkup('An entity field containing an entity reference with additional serialized data.'),
  category: 'lms',
  default_widget: 'lms_reference',
  default_formatter: 'lms_reference_label',
  list_class: EntityReferenceFieldItemList::class,
)]
final class LMSReferenceItem extends EntityReferenceItem {

  /**
   * Check if this step is mandatory.
   *
   * Currently only used if lesson is referenced.
   */
  public function isMandatory(): bool {
    $data = $this->get('data')->getValue();
    if (!\array_key_exists('mandatory', $data)) {
      return FALSE;
    }
    return (bool) $data['mandatory'];
  }

  /**
   * Check if students should be redirected to repeat a failed lesson.
   *
   * Currently only used if lesson is referenced.
   */
  public function autoRepeatFailed(): bool {
    $data = $this->get('data')->getValue();
    if (!\array_key_exists('auto_repeat_failed', $data)) {
      return FALSE;
    }
    return (bool) $data['auto_repeat_failed'];
  }

  /**
   * Get minimum success score for the step.
   *
   * Currently only used if lesson is referenced.
   */
  public function getRequiredScore(): int {
    $data = $this->get('data')->getValue();
    if (!\array_key_exists('required_score', $data)) {
      return 0;
    }
    return (int) $data['required_score'];
  }

  /**
   * Get maximum score.
   *
   * Currently only used if activity is referenced.
   */
  public function getMaxScore(): int {
    $data = $this->get('data')->getValue();
    if (!\array_key_exists('max_score', $data)) {
      return 0;
    }
    return (int) $data['max_score'];
  }

  /**
   * Get time limit.
   *
   * Only used if lesson is referenced.
   */
  public function getTimeLimit(): int {
    $data = $this->get('data')->getValue();
    if (!\array_key_exists('time_limit', $data)) {
      return 0;
    }
    return (int) $data['time_limit'];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $definitions = parent::propertyDefinitions($field_definition);
    $definitions['data'] = MapDataDefinition::create();
    return $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = parent::schema($field_definition);
    $schema['columns']['data'] = [
      'type' => 'blob',
      'size' => 'big',
      'serialize' => TRUE,
    ];
    return $schema;
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line Contravariant stuff.
   */
  public function setValue($values, $notify = TRUE): void {
    if (\array_key_exists('data', $values)) {
      if (!\is_array($values['data'])) {
        if ($values['data'] instanceof MapItem) {
          $values['data'] = $values['data']->getValue();
        }
        elseif (\is_string($values['data'])) {
          $values['data'] = \unserialize($values['data'], ['allowed_classes' => FALSE]);
        }
      }
      $this->values['data'] = $values['data'];
    }

    parent::setValue($values, $notify);
  }

  /**
   * {@inheritdoc}
   */
  public static function getPreconfiguredOptions() {
    // We don't want any additional references of this type in the field UI.
    return [];
  }

}
