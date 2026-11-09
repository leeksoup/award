<?php

declare(strict_types=1);

namespace Drupal\lms_free_text_model\Plugin\ActivityAnswer;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lms\Attribute\ActivityAnswer;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Answer;
use Drupal\lms\Plugin\ActivityAnswerBase;
use Drupal\lms_free_text_model\ModelResponseEvaluator;

/**
 * Free-text answer plugin that reveals one model response.
 *
 * The answer is accepted once it meets the activity minimum length. Saving
 * it reveals the configured model response. Phrase matching and the
 * correct or incorrect feedback styling used by other answer plugins are not
 * applied.
 */
#[ActivityAnswer(
  id: 'free_text_model_response',
  name: new TranslatableMarkup('Free text with model response'),
)]
final class FreeTextModelResponse extends ActivityAnswerBase {

  /**
   * {@inheritdoc}
   */
  public function evaluatedOnSave(Answer $activity): bool {
    // Teacher grades the answer; submission reveals the model response.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getScore(Answer $answer): float {
    return $this->evaluator()->score(
      $this->storedAnswer($answer),
      $this->minimumCharacters($answer->getActivity()),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function answeringForm(
    array &$form,
    FormStateInterface $form_state,
    Answer $answer,
  ): void {
    $submitted = !$answer->isNew();
    $form['#attached']['library'][] = 'lms_free_text_model/model_response';
    $form['answer'] = [
      '#title' => $this->t('Your answer'),
      '#type' => 'textarea',
      '#default_value' => $this->storedAnswer($answer),
      '#rows' => 10,
      '#required' => !$submitted,
      '#disabled' => $submitted,
      '#element_validate' => [[$this, 'validateAnswer']],
      '#minimum_characters' => $this->minimumCharacters(
        $answer->getActivity(),
      ),
    ];

    if ($submitted) {
      $form['model_response'] = $this->modelResponse($answer);
      $form['actions']['submit']['#access'] = FALSE;
      $form['actions']['return_to_course'] = [
        '#type' => 'link',
        '#title' => $this->t('Return to course'),
        '#url' => Url::fromRoute('entity.group.canonical', [
          'group' => $answer->getLessonStatus()->getCourseStatus()->getCourseId(),
        ]),
      ];
    }
  }

  /**
   * Builds model feedback only for a persisted answer.
   */
  public function modelResponse(Answer $answer): array {
    if ($answer->isNew()) {
      return [];
    }
    $activity = $answer->getActivity();
    return $this->evaluator()->feedbackRenderArray(
      $this->fieldString($activity, 'model_response_heading'),
      $this->fieldString($activity, 'model_response'),
    );
  }

  /**
   * Prevents resubmitting a saved response after the model has been revealed.
   */
  public function validateAnsweringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    if (!$answer->isNew()) {
      $form_state->setErrorByName('answer', $this->t('This answer has already been submitted.'));
    }
  }

  /**
   * Marks submissions that reach LMS saving, after its overtime check.
   */
  public function submitAnsweringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    parent::submitAnsweringForm($form, $form_state, $answer);
    $form_state->set('lms_free_text_model_submitted', TRUE);
  }

  /**
   * Element validate callback for the minimum character length.
   */
  public function validateAnswer(
    array &$element,
    FormStateInterface $form_state,
  ): void {
    $minimum_characters = (int) $element['#minimum_characters'];
    if ($minimum_characters === 0) {
      return;
    }

    $value = $element['#value'] ?? '';
    if (!is_string($value)) {
      $value = '';
    }
    $total = $this->evaluator()->countableLength($value);
    if ($total >= $minimum_characters) {
      return;
    }

    $args = [
      '@min_message' => $this->formatPlural(
        $minimum_characters,
        'We are looking for a minimum of @count character,',
        'We are looking for a minimum of @count characters,',
      ),
      '@total_message' => $this->formatPlural(
        $total,
        'and your answer is @count character.',
        'and your answer is @count characters.',
      ),
    ];
    $form_state->setError(
      $element,
      $this->t('@min_message @total_message', $args),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function evaluationDisplay(Answer $answer): array {
    $stored_answer = $this->storedAnswer($answer);
    $answer_markup = '';
    if ($stored_answer !== '') {
      $answer_markup = nl2br(Html::escape($stored_answer));
    }

    $activity = $answer->getActivity();
    $build = [
      'activity' => $this->entityTypeManager
        ->getViewBuilder('lms_activity')
        ->view($activity, 'activity'),
      'answer' => [
        '#type' => 'fieldset',
        '#title' => $this->t('Student answer'),
        'answer' => [
          '#markup' => $answer_markup,
        ],
      ],
    ];

    $feedback = $this->modelResponse($answer);
    if ($feedback !== []) {
      $build['model_response'] = $feedback;
    }
    return $build;
  }

  /**
   * Returns the stored free-text answer.
   */
  private function storedAnswer(Answer $answer): string {
    $data = $answer->getData();
    if (!array_key_exists('answer', $data) || !is_string($data['answer'])) {
      return '';
    }
    return $data['answer'];
  }

  /**
   * Reads the configured minimum length, or zero when it is unset.
   */
  private function minimumCharacters(ActivityInterface $activity): int {
    if (!$activity->hasField('minimum_characters')) {
      return 0;
    }
    $items = $activity->get('minimum_characters');
    if ($items->isEmpty()) {
      return 0;
    }
    return (int) $items->first()->getValue()['value'];
  }

  /**
   * Reads a plain string field, or NULL when it is empty.
   */
  private function fieldString(
    ActivityInterface $activity,
    string $field_name,
  ): ?string {
    if (!$activity->hasField($field_name)) {
      return NULL;
    }
    $items = $activity->get($field_name);
    if ($items->isEmpty()) {
      return NULL;
    }
    $value = $items->value;
    return is_string($value) ? $value : NULL;
  }

  /**
   * The stateless scorer and feedback builder.
   */
  private function evaluator(): ModelResponseEvaluator {
    return new ModelResponseEvaluator();
  }

}
