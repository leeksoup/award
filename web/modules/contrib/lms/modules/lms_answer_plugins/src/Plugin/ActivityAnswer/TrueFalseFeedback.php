<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin\ActivityAnswer;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Attribute\ActivityAnswer;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Answer;
use Drupal\lms_answer_plugins\Plugin\TrueFalseBase;
use Drupal\lms_answer_plugins\Plugin\WithFeedbackPluginTrait;

/**
 * True/False with feedback activity plugin.
 */
#[ActivityAnswer(
  id: 'true_false_feedback',
  name: new TranslatableMarkup('True / false with feedback'),
)]
final class TrueFalseFeedback extends TrueFalseBase {

  use WithFeedbackPluginTrait;

  /**
   * {@inheritdoc}
   */
  public function answeringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    parent::answeringForm($form, $form_state, $answer);
    $form['answer']['#after_build'][] = [$this, 'answerElementAfterBuild'];
    $this->addFeedbackElementsToAnsweringForm($form, $form_state, $answer);
  }

  /**
   * {@inheritdoc}
   */
  private function buildFeedbackRenderArray(bool $is_correct, Answer $answer, ActivityInterface $activity): array {
    $feedback = [];
    $activity = $answer->getActivity();
    $feedback_field = $is_correct ? 'feedback_if_correct' : 'feedback_if_wrong';
    $feedback_field_item_list = $activity->get($feedback_field);

    if (!$feedback_field_item_list->isEmpty()) {
      $feedback['value'] = [
        '#markup' => $feedback_field_item_list->value,
      ];
      $feedback['#attributes']['class'][] = $is_correct ? self::CLASS_CORRECT : self::CLASS_WRONG;
    }

    return $feedback;
  }

  /**
   * After-build callback for the answer element.
   *
   * Required for radio buttons since we can't address them until the form
   * is fully built.
   */
  public function answerElementAfterBuild(array $element, FormStateInterface $form_state): array {
    $answer = $this->getAnswerFromFormState($form_state);
    if (\array_key_exists('answer', $answer->getData()) && $answer->getData()['answer'] !== '') {
      $complete_form = &$form_state->getCompleteForm();
      $this->addAnswerClassesToForm($complete_form, $this->isCorrect($answer), $answer);
      // Return answer element from the complete form to preserve added classes.
      return $complete_form['answer_feedback_wrapper']['answer'];
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  private function addAnswerClassesToForm(array &$form, bool $is_correct, Answer $answer): void {
    $current_answer = $answer->getData()['answer'] ?? NULL;

    if ($current_answer !== NULL && $current_answer !== '') {
      $class = $is_correct ? self::CLASS_CORRECT : self::CLASS_WRONG;
      if (\array_key_exists($current_answer, $form['answer_feedback_wrapper']['answer'])) {
        $form['answer_feedback_wrapper']['answer'][$current_answer]['#wrapper_attributes']['class'][] = $class;
      }
    }
  }

}
