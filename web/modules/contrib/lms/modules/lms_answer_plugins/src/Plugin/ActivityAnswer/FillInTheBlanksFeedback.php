<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin\ActivityAnswer;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Attribute\ActivityAnswer;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Answer;
use Drupal\lms_answer_plugins\Plugin\DragAndDropBase;

/**
 * Fill in the blanks activity plugin with feedback.
 */
#[ActivityAnswer(
  id: 'fill_in_the_blanks_feedback',
  name: new TranslatableMarkup('Fill in the Blanks with Feedback')
)]
final class FillInTheBlanksFeedback extends DragAndDropBase {

  use \Drupal\lms_answer_plugins\Plugin\WithFeedbackPluginTrait;

  /**
   * {@inheritdoc}
   */
  public function answeringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    parent::answeringForm($form, $form_state, $answer);
    $activity = $answer->getActivity();

    // Add the answer key to drupalSettings for feedback functionality.
    $form['#attached']['drupalSettings']['lms']['dragAndDrop'][$activity->id()]['correctMapping'] = $this->getCorrectMapping($activity);

    // Call the trait, which will correctly find and wrap the form elements.
    $this->addFeedbackElementsToAnsweringForm($form, $form_state, $answer);
  }

  /**
   * {@inheritdoc}
   */
  private function buildFeedbackRenderArray(bool $is_correct, Answer $answer, ActivityInterface $activity): array {
    $feedback = [];
    $feedback_field_name = $is_correct ? 'field_feedback_if_correct' : 'field_feedback_if_wrong';
    $feedback_field = $activity->get($feedback_field_name);

    if (!$feedback_field->isEmpty()) {
      $feedback['value'] = [
        '#markup' => $feedback_field->value,
      ];
      $feedback['#attributes']['class'][] = $is_correct ? self::CLASS_CORRECT : self::CLASS_WRONG;
    }

    return $feedback;
  }

  /**
   * {@inheritdoc}
   */
  private function addAnswerClassesToForm(array &$form, bool $is_correct, Answer $answer): void {
    $activity = $answer->getActivity();
    $data = $answer->getData();

    $student_answers = [];
    if (array_key_exists('answer', $data) && is_string($data['answer'])) {
      $student_answers = \json_decode($data['answer'], TRUE);
    }

    if (!\is_array($student_answers)) {
      $student_answers = [];
    }

    $correct_mapping = $this->getCorrectMapping($activity);
    $results = [];

    foreach ($correct_mapping as $drop_zone_id => $correct_draggable_id) {
      if (
        array_key_exists($drop_zone_id, $student_answers) &&
        $student_answers[$drop_zone_id] === $correct_draggable_id
      ) {
        $results[$drop_zone_id] = 'correct';
      }
      else {
        $results[$drop_zone_id] = 'wrong';
      }
    }

    // Attach the results to the wrapper created by the trait.
    $form['answer_feedback_wrapper']['#attached']['drupalSettings']['lms']['dragAndDrop'][$activity->id()]['feedbackResults'] = $results;
  }

}
