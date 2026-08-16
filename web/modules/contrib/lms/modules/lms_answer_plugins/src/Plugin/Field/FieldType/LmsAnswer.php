<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the 'lms_answer' entity field type.
 */
#[FieldType(
  id: "lms_answer",
  label: new TranslatableMarkup("LMS answer"),
  description: new TranslatableMarkup("Stores answer and correct / incorrect indicators"),
  category: "lms",
  default_widget: "lms_answer",
)]
final class LmsAnswer extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    return [
      'answer' => DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Answer'))
        ->setSetting('case_sensitive', $field_definition->getSetting('case_sensitive'))
        ->setRequired(TRUE),
      'correct' => DataDefinition::create('boolean')
        ->setLabel(\t('Correct?'))
        ->setRequired(TRUE),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'answer' => [
          'type' => 'text',
          'size' => 'big',
        ],
        'correct' => [
          'type' => 'int',
          'size' => 'tiny',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $value = $this->get('answer')->getString();
    return $value === '';
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();
    return [
      'answer' => $random->sentences(10),
      'correct' => (bool) \mt_rand(0, 1),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'answer';
  }

  /**
   * Is this the correct answer?
   */
  public function isCorrect(): bool {
    return (bool) $this->get('correct')->getValue();
  }

}
