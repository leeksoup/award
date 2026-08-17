<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\source;

use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Reads supported Anu single/multiple choice question wrappers.
 *
 * @MigrateSource(
 *   id = "anu_assessment_question"
 * )
 */
final class AnuAssessmentQuestion extends SourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'paragraph_id' => $this->t('Question wrapper paragraph ID'),
      'activity_type' => $this->t('Target activity type'),
      'name' => $this->t('Question name'),
      'question' => $this->t('Formatted question'),
      'answers' => $this->t('Ordered answer choices'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds(): array {
    return ['paragraph_id' => ['type' => 'integer']];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return 'Anu LMS supported assessment questions';
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \Iterator {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $assessment_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'module_assessment')
      ->sort('nid')
      ->execute();

    $wrappers = [];
    foreach ($node_storage->loadMultiple($assessment_ids) as $assessment) {
      foreach ($assessment->get('field_module_assessment_items')->referencedEntities() as $item) {
        if (in_array($item->bundle(), ['question_single_choice', 'question_multi_choice'], TRUE)) {
          $wrappers[(int) $item->id()] = $item;
        }
      }
    }

    foreach ($wrappers as $wrapper) {
      $question = $wrapper->get('field_question')->entity;
      if ($question === NULL) {
        throw new MigrateException(sprintf(
          'Missing assessment question entity in paragraph %s.',
          $wrapper->id(),
        ));
      }

      $answers = [];
      foreach ($question->get('field_options')->referencedEntities() as $option) {
        $answer = trim((string) $option->get('field_single_multi_choice_value')->value);
        if ($answer !== '') {
          $answers[] = [
            'answer' => $answer,
            'correct' => (bool) $option->get('field_single_multi_choice_right')->value,
          ];
        }
      }
      if ($answers === []) {
        throw new MigrateException(sprintf(
          'Question paragraph %s has no answer options.',
          $wrapper->id(),
        ));
      }
      if (!array_filter($answers, static fn(array $answer): bool => $answer['correct'])) {
        throw new MigrateException(sprintf(
          'Question paragraph %s has no correct answer.',
          $wrapper->id(),
        ));
      }

      $name = trim((string) $question->label());
      yield [
        'paragraph_id' => (int) $wrapper->id(),
        'activity_type' => $wrapper->bundle() === 'question_multi_choice' ? 'multiple_choice' : 'single_choice',
        'name' => mb_strimwidth($name, 0, 255, '…'),
        'question' => [[
          'value' => $name,
          'format' => 'minimal_html',
        ]],
        'answers' => $answers,
      ];
    }
  }

}
