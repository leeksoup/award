<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the 'lms_phrase_feedback' entity field type.
 */
#[FieldType(
  id: "lms_phrase_feedback",
  label: new TranslatableMarkup("LMS phrase feedback"),
  description: new TranslatableMarkup("Stores phrase match and corresponding feedback"),
  category: "lms",
  default_widget: "lms_phrase_feedback",
)]
final class LmsPhraseFeedback extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    return [
      'phrase' => DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Match Phrase'))
        ->setRequired(TRUE),
      'feedback_present' => DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Feedback if present')),
      'feedback_absent' => DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Feedback if absent')),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'phrase' => [
          'type' => 'varchar',
          'length' => 255,
        ],
        'feedback_present' => [
          'type' => 'text',
          'size' => 'big',
        ],
        'feedback_absent' => [
          'type' => 'text',
          'size' => 'big',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $value = $this->get('phrase')->getString();
    return $value === '';
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();
    return [
      'phrase' => $random->word(10),
      'feedback_present' => $random->paragraphs(1),
      'feedback_absent' => $random->paragraphs(1),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'phrase';
  }

}
