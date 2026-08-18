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
    $rows = [];

    $assessment_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'module_assessment')
      ->sort('nid')
      ->execute();
    foreach ($node_storage->loadMultiple($assessment_ids) as $assessment) {
      $rows += $this->rowsFromSequence(
        $assessment->get('field_module_assessment_items')->referencedEntities(),
      );
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
      $items = [];
      foreach ($lesson->get('field_module_lesson_content')->referencedEntities() as $section) {
        if (!$section->hasField('field_lesson_section_content')) {
          continue;
        }
        $items = array_merge(
          $items,
          $section->get('field_lesson_section_content')->referencedEntities(),
        );
      }
      $rows += $this->rowsFromSequence($items);
    }

    foreach ($rows as $row) {
      yield $row;
    }
  }

  /**
   * Builds migration source rows from an ordered assessment/question sequence.
   */
  private function rowsFromSequence(array $items): array {
    $rows = [];
    $count = count($items);

    for ($index = 0; $index < $count; $index++) {
      $item = $items[$index];
      if (!$item instanceof ContentEntityInterface) {
        continue;
      }

      if (AnuLessonBlockHelper::isFreeTextQuestionBundle($item->bundle())) {
        $group = [$item];
        while (
          isset($items[$index + 1])
          && $items[$index + 1] instanceof ContentEntityInterface
          && AnuLessonBlockHelper::isFreeTextQuestionBundle($items[$index + 1]->bundle())
        ) {
          $group[] = $items[++$index];
        }

        $row = $this->freeTextRow($group);
        $rows[(int) $row['paragraph_id']] = $row;
        continue;
      }

      if (in_array($item->bundle(), self::CHOICE_BUNDLES, TRUE)) {
        $row = $this->choiceRow($item);
        $rows[(int) $row['paragraph_id']] = $row;
      }
    }

    return $rows;
  }

  /**
   * Builds one LMS select activity row from an Anu choice question wrapper.
   */
  private function choiceRow(ContentEntityInterface $wrapper): array {
    $question = $this->questionEntity($wrapper);
    $prompt = $this->prompt($question);
    $activity_name = $this->activityName(
      (string) $prompt[0]['value'],
      (int) $wrapper->id(),
    );

    return [
      'paragraph_id' => (int) $wrapper->id(),
      'activity_type' => $wrapper->bundle() === 'question_multi_choice'
        ? 'multiple_choice'
        : 'single_choice',
      'name' => $activity_name,
      'question' => $prompt,
      'questions' => NULL,
      'answers' => $this->answers($wrapper, $question),
    ];
  }

  /**
   * Builds one LMS free_text activity row from adjacent Anu question wrappers.
   */
  private function freeTextRow(array $wrappers): array {
    $first = reset($wrappers);
    \assert($first instanceof ContentEntityInterface);

    $questions = [];
    foreach ($wrappers as $wrapper) {
      if (!$wrapper instanceof ContentEntityInterface) {
        continue;
      }
      $questions[] = $this->prompt($this->questionEntity($wrapper))[0];
    }

    return [
      'paragraph_id' => (int) $first->id(),
      'activity_type' => 'free_text',
      'name' => $this->activityName(
        (string) ($questions[0]['value'] ?? ''),
        (int) $first->id(),
      ),
      'question' => NULL,
      'questions' => $questions,
      'answers' => NULL,
    ];
  }

  /**
   * Loads the Anu assessment question entity referenced by a wrapper.
   */
  private function questionEntity(
    ContentEntityInterface $wrapper,
  ): ContentEntityInterface {
    $question = $wrapper->get('field_question')->entity;
    if (!$question instanceof ContentEntityInterface) {
      throw new MigrateException(sprintf(
        'Missing assessment question entity in paragraph %s.',
        $wrapper->id(),
      ));
    }
    return $question;
  }

  /**
   * Builds one formatted LMS question field item.
   */
  private function prompt(ContentEntityInterface $question): array {
    return [[
      'value' => trim((string) $question->label()),
      'format' => 'minimal_html',
    ]];
  }

  /**
   * Builds a compact activity title from the full Anu question prompt.
   */
  private function activityName(string $prompt, int $paragraph_id): string {
    return AnuLessonBlockHelper::compactTitle(
      $prompt,
      'Question ' . $paragraph_id,
    );
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
