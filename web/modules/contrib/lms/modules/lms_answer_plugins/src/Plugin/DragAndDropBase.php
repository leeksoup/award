<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin;

use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Answer;
use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms\Plugin\ActivityAnswerBase;

/**
 * Base class for drag-and-drop activity plugins.
 *
 * Provides default implementations for text-based fill-in-the-blanks logic.
 * Subclasses handling different media types should override these methods.
 */
abstract class DragAndDropBase extends ActivityAnswerBase {

  /**
   * Regex to find bracketed answers in the question text.
   */
  protected const BLANK_REGEX = '/\[([^\]]+)\]/';

  /**
   * {@inheritdoc}
   */
  public function answeringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    $activity = $answer->getActivity();
    $answer_data = $answer->getData();

    $default_value = '';
    if (\array_key_exists('answer', $answer_data) && $answer_data['answer'] !== NULL) {
      $default_value = $answer_data['answer'];
    }

    $form['answer_matches_json'] = [
      '#type' => 'hidden',
      '#default_value' => $default_value,
      '#attributes' => ['data-lms-selector' => 'drag-drop-answer'],
      '#parents' => ['answer'],
    ];

    $form['answer'] = [
      '#type' => 'component',
      '#component' => 'lms_answer_plugins:lms_drag_and_drop',
      '#attributes' => [
        'data-activity-id' => $activity->id(),
      ],
      '#slots' => [
        'question_display' => $this->buildQuestionDisplay($activity),
        'draggable_pool' => $this->buildDraggablePool($activity, $form_state),
        'hidden_answer_field' => &$form['answer_matches_json'],
      ],
      '#element_validate' => [[$this, 'validateAnswerElement']],
    ];

    // Attach basic structure settings to the form for JavaScript.
    $form['#attached']['drupalSettings']['lms']['dragAndDrop'][$activity->id()] = [
      'draggable' => $this->getDraggable($activity),
      'dropZones' => $this->getDropZones($activity),
    ];

    $instructionsText = $this->getInstructionsText();
    if ((string) $instructionsText !== '') {
      $form['answer']['#slots']['instructions']['#markup'] = '<p class="drag-drop-instructions">' . $instructionsText . '</p>';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getScore(Answer $answer): float {
    $data = $answer->getData();

    $student_answers = [];
    if (\array_key_exists('answer', $data) && \is_string($data['answer']) && $data['answer'] !== '') {
      $student_answers = \json_decode($data['answer'], TRUE);
    }

    $correct_mapping = $this->getCorrectMapping($answer->getActivity());

    if ($student_answers === NULL || \count($correct_mapping) === 0) {
      return 0.0;
    }

    $score = 0;
    foreach ($correct_mapping as $drop_zone_id => $correct_draggable_id) {
      // Ensure strict integer comparison.
      if (
        \array_key_exists($drop_zone_id, $student_answers) &&
        (int) $student_answers[$drop_zone_id] === (int) $correct_draggable_id
      ) {
        $score++;
      }
    }

    return (float) ($score / \count($correct_mapping));
  }

  /**
   * {@inheritdoc}
   */
  public function evaluationDisplay(Answer $answer): array {
    $activity = $answer->getActivity();
    $answer_data = $answer->getData();
    $student_answers = [];

    if (\array_key_exists('answer', $answer_data) && \is_string($answer_data['answer'])) {
      $student_answers = \json_decode($answer_data['answer'], TRUE);
    }

    // Map draggable IDs to their text for display lookup.
    $draggable_items = $this->getDraggable($activity);
    $id_to_text = [];
    foreach ($draggable_items as $item) {
      $id_to_text[$item['id']] = $item['text'];
    }

    $question_text = $activity->get('field_question_text')->value;
    $drop_zone_counter = 0;

    // Reconstruct the text, replacing blanks with the student's selected words.
    $markup = \preg_replace_callback(self::BLANK_REGEX, function ($matches) use (&$drop_zone_counter, $student_answers, $id_to_text) {
      $drop_zone_counter++;
      $output_text = '____';

      if (\is_array($student_answers) && \array_key_exists($drop_zone_counter, $student_answers)) {
        $answer_id = $student_answers[$drop_zone_counter];
        if (\array_key_exists($answer_id, $id_to_text)) {
          $output_text = $id_to_text[$answer_id];
        }
      }

      return '<span class="lms-drag-drop-filled-blank">' . Markup::create($output_text) . '</span>';
    }, $question_text);

    return [
      'activity' => $this->entityTypeManager->getViewBuilder('lms_activity')->view($activity, 'activity'),
      'answer' => [
        '#type' => 'fieldset',
        '#title' => $this->t('Student answer'),
        'answer' => [
          '#markup' => Markup::create($markup),
        ],
        '#attached' => [
          'library' => ['lms_answer_plugins/drag_and_drop_css'],
        ],
      ],
    ];
  }

  /**
   * Builds the render array for the pool of draggable items.
   *
   * @param \Drupal\lms\Entity\ActivityInterface $activity
   *   The activity entity.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   A render array for the draggable items pool.
   */
  protected function buildDraggablePool(ActivityInterface $activity, FormStateInterface $form_state): array {
    // Preserve the order of draggable items across AJAX rebuilds.
    if ($form_state->has('draggable_pool_order')) {
      $draggable = $form_state->get('draggable_pool_order');
    }
    else {
      $draggable = $this->getDraggable($activity);
      \shuffle($draggable);
      $form_state->set('draggable_pool_order', $draggable);
    }

    $items = [];
    foreach ($draggable as $item) {
      $items[] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $item['text'],
        '#attributes' => [
          'class' => ['draggable-item'],
          'data-draggable-id' => $item['id'],
          'draggable' => 'true',
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['draggable-pool']],
      'items' => $items,
    ];
  }

  /**
   * Element validation handler for the answering form.
   */
  public function validateAnswerElement(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    $answer_value = $form_state->getValue('answer');
    if ($answer_value === NULL || $answer_value === '') {
      $form_state->setError($element, $this->t('Please drag your answers into the blank spaces.'));
      return;
    }

    $student_answers = \json_decode($answer_value, TRUE);
    if ($student_answers === NULL) {
      $student_answers = [];
    }

    $form_object = $form_state->getFormObject();
    \assert($form_object instanceof ContentEntityFormInterface);
    $answer_entity = $form_object->getEntity();
    \assert($answer_entity instanceof AnswerInterface);
    $activity = $answer_entity->getActivity();

    $total_drop_zones = \count($this->getDropZones($activity));
    $filled_drop_zones = \count($student_answers);

    if ($filled_drop_zones < $total_drop_zones) {
      $form_state->setError($element, $this->t('Please fill in all the answers first.'));
    }
  }

  /**
   * Parses the question text to build the display with drop zones.
   */
  protected function buildQuestionDisplay(ActivityInterface $activity): array {
    $question_text = $activity->get('field_question_text')->value;
    $drop_zone_id_counter = 0;

    $markup = \preg_replace_callback(self::BLANK_REGEX, function ($matches) use (&$drop_zone_id_counter) {
      $drop_zone_id_counter++;
      // Include a non-breaking space to ensure the div is never empty.
      return '<div class="drop-zone" data-drop-zone-id="' . $drop_zone_id_counter . '">&nbsp;</div>';
    }, $question_text);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['question-text']],
      '#markup' => Markup::create($markup),
    ];
  }

  /**
   * Create draggable items from the question text & distraction words.
   */
  protected function getDraggable(ActivityInterface $activity): array {
    // Extract correct words from the question text.
    $question_text = $activity->get('field_question_text')->value;
    $all_words = [];
    if (\preg_match_all(self::BLANK_REGEX, $question_text, $matches) > 0) {
      $all_words = $matches[1];
    }

    // Add distraction words to provide incorrect answer items.
    $distraction_values = \array_column($activity->get('field_distraction_words')->getValue(), 'value');
    $all_words = \array_merge($all_words, \array_filter($distraction_values, fn($v) => $v !== '' && $v !== NULL));

    // Sort alphabetically to decouple ID from position and prevent cheating.
    \sort($all_words, \SORT_STRING);

    // Create draggable items with generic integer IDs.
    $draggable = [];
    foreach ($all_words as $index => $word) {
      $draggable[] = [
        'id' => $index + 1,
        'text' => $word,
      ];
    }

    return $draggable;
  }

  /**
   * Determines drop zones based on the question text format.
   */
  protected function getDropZones(ActivityInterface $activity): array {
    $zones = [];
    $question_text = $activity->get('field_question_text')->value;
    if (\preg_match_all(self::BLANK_REGEX, $question_text, $matches) > 0) {
      $blank_count = \count($matches[0]);
    }
    else {
      $blank_count = 0;
    }

    for ($i = 1; $i <= $blank_count; $i++) {
      $zones[] = $i;
    }

    return $zones;
  }

  /**
   * Maps drop zones to their correct answers.
   */
  protected function getCorrectMapping(ActivityInterface $activity): array {
    $mapping = [];

    // Get the correct words in order of appearance in the text.
    $question_text = $activity->get('field_question_text')->value;
    $correct_words = [];
    if (\preg_match_all(self::BLANK_REGEX, $question_text, $matches) > 0) {
      $correct_words = $matches[1];
    }

    // Get the available draggable items (which are sorted).
    $draggable = $this->getDraggable($activity);

    // Track which IDs have been assigned, to handle duplicate answer words.
    $used_ids = [];
    foreach ($correct_words as $index => $word) {
      $drop_zone_id = $index + 1;
      // Find the ID for this word in the draggable list.
      foreach ($draggable as $item) {
        if ($item['text'] === $word && !\in_array($item['id'], $used_ids, TRUE)) {
          $mapping[$drop_zone_id] = $item['id'];
          $used_ids[] = $item['id'];
          break;
        }
      }
    }

    return $mapping;
  }

  /**
   * Answer instructions text. Can be overridden by individual plugins.
   */
  protected function getInstructionsText(): string|TranslatableMarkup {
    return $this->t('Drag the words below to fill in the correct blanks in the text.');
  }

}
