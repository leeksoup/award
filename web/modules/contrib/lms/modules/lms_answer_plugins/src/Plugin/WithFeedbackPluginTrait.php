<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Answer;

/**
 * Trait providing shared helpers for feedback-capable activity plugins.
 */
trait WithFeedbackPluginTrait {

  use StringTranslationTrait;

  /**
   * Adds common feedback elements to an activity answering form.
   *
   * This should be called from the plugin's answeringForm() method.
   *
   * @param array $form
   *   The form array to be modified.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\lms\Entity\Answer $answer
   *   The answer entity.
   *
   * PHPStan wants this method to be private since the containing class is
   * final. We need it to be protected so we can call it from a plugin.
   *
   * @phpstan-ignore-next-line
   */
  protected function addFeedbackElementsToAnsweringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    $activity = $answer->getActivity();
    $activity_id = $activity->id();
    $wrapper_id = 'activity-feedback-wrapper-' . $activity_id;

    // Add a wrapper around the answer and feedback, allowing us to update both.
    $form['answer_feedback_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => $wrapper_id],
      'answer' => $form['answer'],
      'feedback' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['answer-feedback-wrapper'],
          'data-lms-selector' => 'feedback-' . $activity_id,
        ],
        '#weight' => 10,
      ],
    ];
    unset($form['answer']);

    // Build the feedback state on revisit, unless there was a validation error.
    $this->setCurrentAnswerFromFormState($form_state, $answer);
    $existing_answer_data = $answer->getData();
    $has_existing_answer = \array_key_exists('answer', $existing_answer_data)
      && $existing_answer_data['answer'] !== ''
      && $existing_answer_data['answer'] !== [];
    if ($has_existing_answer) {
      $is_correct = $this->isCorrect($answer);
      $feedback_content = $this->buildFeedbackRenderArray($is_correct, $answer, $activity);
      $form['answer_feedback_wrapper']['feedback'] = \array_merge_recursive($form['answer_feedback_wrapper']['feedback'], $feedback_content);
      $this->addAnswerClassesToForm($form, $is_correct, $answer);
    }

    // Hide submit button on initial display if no answer has been given yet.
    if ($form_state->getValue('answer') === NULL && !\array_key_exists('answer', $answer->getData())) {
      if (\array_key_exists('submit', $form['actions'])) {
        $form['actions']['submit']['#access'] = FALSE;
      }
    }

    // Add the 'Check Answer' button pointing to our AJAX callback.
    $form['actions']['check'] = [
      '#type' => 'button',
      '#value' => $this->t('Check Answer'),
      '#ajax' => [
        'callback' => [$this, 'ajaxFeedbackCallback'],
      ],
      '#weight' => 1,
    ];

    if (\array_key_exists('submit', $form['actions'])) {
      $form['actions']['submit']['#weight'] = 2;
    }
  }

  /**
   * Universal AJAX callback for all feedback-capable plugins.
   */
  public function ajaxFeedbackCallback(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $answer_feedback_wrapper = $form['answer_feedback_wrapper'];
    $wrapper_id = '#' . $answer_feedback_wrapper['#attributes']['id'];

    $answer_feedback_wrapper['status_messages'] = [
      '#type' => 'status_messages',
      '#weight' => 1,
    ];

    // Make the submit button visible if validation passed.
    if (!$form_state::hasAnyErrors()) {
      $form['actions']['submit']['#access'] = TRUE;
    }
    else {
      unset($answer_feedback_wrapper['feedback']);
      $form['actions']['submit']['#access'] = FALSE;
    }

    // Build a response that replaces both the answer/feedback wrapper and the
    // actions wrapper to ensure that everything is updated.
    $response->addCommand(new ReplaceCommand('.form-actions', $form['actions']));
    $response->addCommand(new ReplaceCommand($wrapper_id, $answer_feedback_wrapper));
    return $response;
  }

  /**
   * Retrieve the current answer from form state and update the answer entity.
   */
  private function setCurrentAnswerFromFormState(FormStateInterface $form_state, Answer $answer): void {
    $form_state->cleanValues();
    $values = $form_state->getValues();
    // The form wasn't submitted yet, rely on original values.
    if (\count($values) === 0) {
      return;
    }
    $answer->setData($values);
  }

  /**
   * Retrieve the Answer entity from the current browser form state.
   *
   * PHPStan wants this method to be private since the containing class is
   * final. We need it to be protected so we can call it from a plugin.
   *
   * @phpstan-ignore-next-line
   */
  protected function getAnswerFromFormState(FormStateInterface $form_state): Answer {
    $form_object = $form_state->getFormObject();
    \assert($form_object instanceof ContentEntityFormInterface);
    $answer = $form_object->getEntity();
    \assert($answer instanceof Answer);
    return $answer;
  }

  /**
   * Build the render array for the feedback message area.
   */
  abstract private function buildFeedbackRenderArray(bool $is_correct, Answer $answer, ActivityInterface $activity): array;

  /**
   * Add correctness classes to the form elements for visual feedback.
   */
  abstract private function addAnswerClassesToForm(array &$form, bool $is_correct, Answer $answer): void;

}
