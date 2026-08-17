<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\source;

use Drupal\anu_to_lms_migrate\AnuLessonBlockHelper;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Reads supported Anu assessment question wrappers.
 *
 * @MigrateSource(
 *   id = "anu_assessment_question"
 * )
 */
final class AnuAssessmentQuestion extends SourcePluginBase {

  /**
   * Anu question wrapper bundles supported by the migration.
   */
  private const SUPPORTED_BUNDLES = [
    'question_single_choice',
    'question_multi_choice',
    'question_short_answer',
    'question_long_answer',
  ];

  /**
   * Anu question wrapper bundles with ordered answer options.
   */
  private const CHOICE_BUNDLES = [
    'question_single_choice',
    'question_multi_choice',
  ];

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'paragraph_id' => $this->t('Question wrapper paragraph ID'),
      'activity_type' => $this->t('Target activity type'),
      'name' => $this->t('Question name'),
      'question' => $this->t('Formatted question'),
      'questions' => $this->t('Formatted free-text question prompts'),
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
    $wrappers = [];

    $assessment_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'module_assessment')
      ->sort('nid')
      ->execute();
    foreach ($node_storage->loadMultiple($assessment_ids) as $assessment) {
      foreach ($assessment->get('field_module_assessment_items')->referencedEntities() as $item) {
        if (in_array($item->bundle(), self::SUPPORTED_BUNDLES, TRUE)) {
          $wrappers[(int) $item->id()] = $item;
        }
      }
    }

    $lesson_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'module_lesson')
      ->sort('nid')
      ->execute();
    foreach ($node_storage->loadMultiple($lesson_ids) as $lesson) {
      if (!$lesson->hasField('field_module_lesson_content')) {
        continue;
      }
      foreach ($lesson->get('field_module_lesson_content')->referencedEntities() as $section) {
        if (!$section->hasField('field_lesson_section_content')) {
          continue;
        }
        foreach ($section->get('field_lesson_section_content')->referencedEntities() as $item) {
          if (in_array($item->bundle(), self::SUPPORTED_BUNDLES, TRUE)) {
            $wrappers[(int) $item->id()] = $item;
          }
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

      $name = trim((string) $question->label());
      $heading = AnuLessonBlockHelper::headingForActivity($wrapper);
      $prompt = [[
        'value' => $name,
        'format' => 'minimal_html',
      ]];

      if (in_array($wrapper->bundle(), self::CHOICE_BUNDLES, TRUE)) {
        yield [
          'paragraph_id' => (int) $wrapper->id(),
          'activity_type' => $wrapper->bundle() === 'question_multi_choice'
            ? 'multiple_choice'
            : 'single_choice',
          'name' => mb_strimwidth($heading ?? $name, 0, 255, '...'),
          'question' => $prompt,
          'questions' => NULL,
          'answers' => $this->answers($wrapper, $question),
        ];
        continue;
      }

      yield [
        'paragraph_id' => (int) $wrapper->id(),
        'activity_type' => 'free_text',
        'name' => mb_strimwidth($heading ?? $name, 0, 255, '...'),
        'question' => NULL,
        'questions' => $prompt,
        'answers' => NULL,
      ];
    }
  }

  /**
   * Builds ordered answer options for a single/multiple-choice Anu question.
   */
  private function answers(
    ContentEntityInterface $wrapper,
    ContentEntityInterface $question,
  ): array {
    if (!$question->hasField('field_options')) {
      throw new MigrateException(sprintf(
        'Question paragraph %s has no answer options field.',
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

    return $answers;
  }

}
