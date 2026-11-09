<?php

declare(strict_types=1);

namespace Drupal\lms_free_text_model\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Stores an activity rubric check independently of learner responses.
 */
#[FieldType(
  id: 'model_response_rubric_check',
  label: new TranslatableMarkup('Model response rubric check'),
  default_widget: 'model_response_rubric_check',
  constraints: ['LmsFreeTextModelRubric' => []],
)]
final class RubricCheck extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties = [];
    $labels = [
      'label' => new TranslatableMarkup('Label'),
      'mode' => new TranslatableMarkup('Pattern type'),
      'pattern' => new TranslatableMarkup('Pattern'),
      'nudge' => new TranslatableMarkup('Nudge text'),
    ];
    foreach (['label' => 128, 'mode' => 16, 'pattern' => 1024, 'nudge' => 2048] as $name => $length) {
      $properties[$name] = DataDefinition::create('string')
        ->setLabel($labels[$name])
        ->addConstraint('Length', ['max' => $length]);
    }
    $properties['weight'] = DataDefinition::create('float')->setLabel(new TranslatableMarkup('Points weight'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    $columns = [];
    foreach (['label' => 128, 'mode' => 16, 'pattern' => 1024, 'nudge' => 2048] as $name => $length) {
      $columns[$name] = ['type' => 'varchar', 'length' => $length];
    }
    $columns['weight'] = ['type' => 'float'];
    return ['columns' => $columns];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    foreach (['label', 'pattern', 'nudge'] as $name) {
      if (trim((string) $this->get($name)->getValue()) !== '') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'pattern';
  }

}
