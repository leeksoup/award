<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin\ActivityAnswer;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Attribute\ActivityAnswer;
use Drupal\lms\Entity\Answer;
use Drupal\lms\Plugin\ActivityAnswerBase;

/**
 * Long Answer activity plugin.
 */
#[ActivityAnswer(
  id: 'free_text',
  name: new TranslatableMarkup('Free text'),
)]
final class FreeText extends ActivityAnswerBase {

  /**
   * {@inheritdoc}
   */
  public function evaluatedOnSave(Answer $activity): bool {
    // Manually evaluated activity.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function answeringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    $activity = $answer->getActivity();
    $text_format = $activity->get('text_format')->isEmpty() ? NULL : $activity->get('text_format')->target_id;

    $activity = $answer->getActivity();
    $data = $answer->getData();

    $form['answer'] = [
      '#tree' => TRUE,
      '#type' => 'container',
      '#element_validate' => [[$this, 'validateAnswers']],
    ];
    foreach ($activity->get('questions') as $delta => $question_item) {
      $element = [
        '#title' => $question_item->getValue()['value'],
        '#type' => $text_format === NULL ? 'textarea' : 'text_format',
        '#default_value' => $data['answer'][$delta] ?? [],
      ];
      if ($text_format !== NULL) {
        $element['#format'] = $text_format;
        $element['#allowed_formats'] = [$text_format];
      }

      $form['answer'][$delta] = $element;
    }

  }

  /**
   * Element validate callback.
   */
  public function validateAnswers(array $element, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Entity\ContentEntityFormInterface */
    $form_object = $form_state->getFormObject();
    $answer = $form_object->getEntity();
    \assert($answer instanceof Answer);
    $activity = $answer->getActivity();
    if ($activity->get('minimum_characters')->isEmpty()) {
      return;
    }
    $minimum_characters = $activity->get('minimum_characters')->first()->getValue()['value'];
    if ($minimum_characters === '0') {
      return;
    }
    foreach ($form_state->getValues()['answer'] as $delta => $entry) {
      $total = 0;
      $value = strip_tags(!$activity->get('text_format')->isEmpty() ? $entry['value'] : $entry);
      $value = preg_replace('/\r\n?/', '', $value);
      $total += mb_strlen($value);
      if ($total < $minimum_characters) {
        $args = [
          '@min_message' => $this->formatPlural($minimum_characters, 'you must enter at least @count character,', 'you must enter at least @count characters,'),
          '@total_message' => $this->formatPlural($total, '@count character has been entered.', '@count characters have been entered.'),
        ];
        $form_state->setError($element[$delta], $this->t('@min_message @total_message', $args));
        break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function evaluationDisplay(Answer $answer): array {
    $activity = $answer->getActivity();
    $data = $answer->getData()['answer'];

    $answers = [];
    foreach ($activity->get('questions') as $delta => $item) {
      $answers[$delta] = [
        '#type' => 'fieldset',
      ];

      $answers[$delta]['title'] = [
        '#type' => 'container',
        'question' => $item->view(),
      ];

      if (is_array($data[$delta])) {
        $answers[$delta]['answer'] = [
          '#type' => 'container',
          'answer' => [
            '#type' => 'processed_text',
            '#format' => $data[$delta]['format'],
            '#text' => $data[$delta]['value'],
          ],
        ];
      }
      else {
        $answers[$delta]['answer'] = [
          '#type' => 'container',
          'answer' => [
            '#markup' => nl2br(Html::escape($data[$delta])),
          ],
        ];
      }
    }

    return [
      // Render activity.
      'activity' => $this->entityTypeManager->getViewBuilder('lms_activity')->view($answer->getActivity(), 'activity'),
      // Add answer.
      'answer' => [
        '#type' => 'fieldset',
        '#title' => $this->t('Student answer'),
        'answer' => $answers,
      ],
    ];
  }

}
