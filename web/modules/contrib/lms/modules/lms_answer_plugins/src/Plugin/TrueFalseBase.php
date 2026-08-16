<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\lms\Entity\Answer;
use Drupal\lms\Plugin\ActivityAnswerBase;

/**
 * True/False plugin base class.
 */
abstract class TrueFalseBase extends ActivityAnswerBase {

  /**
   * {@inheritdoc}
   */
  public function getScore(Answer $answer): float {
    $expected = $answer->getActivity()->get('bool_expected')->value;
    $data = $answer->getData();
    if (!\array_key_exists('answer', $data)) {
      return 0;
    }
    if ($data['answer'] === $expected) {
      return 1;
    }
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function answeringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    $activity = $answer->getActivity();
    $data = $answer->getData();
    $form['answer'] = [
      '#title' => $this->t('Your answer'),
      '#type' => 'radios',
      '#options' => [
        '1' => $this->t('True'),
        '0' => $this->t('False'),
      ],
      '#default_value' => $data['answer'] ?? NULL,
    ];

    // Include data from the activity entity.
    $description_field = $activity->get('description');
    if (!$description_field->isEmpty()) {
      $form['answer']['#description'] = $description_field->value;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAnswerRenderable(Answer $answer): array {
    $data = $answer->getData();
    return [
      '#markup' => (bool) $data['answer'] ? $this->t('true') : $this->t('false'),
    ];
  }

}
