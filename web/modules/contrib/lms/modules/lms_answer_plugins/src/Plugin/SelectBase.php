<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\lms\Entity\Answer;
use Drupal\lms\Plugin\ActivityAnswerBase;
use Drupal\lms_answer_plugins\Plugin\Field\FieldType\LmsAnswer;

/**
 * Base class for select activity plugins.
 */
abstract class SelectBase extends ActivityAnswerBase implements PluginFormInterface {

  protected const ELEMENT_TYPE = NULL;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['selection_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Selection type'),
      '#description' => $this->t('Choose whether students can select single or multiple answers.'),
      '#options' => [
        'single' => $this->t('Single selection (radio buttons)'),
        'multiple' => $this->t('Multiple selection (checkboxes)'),
      ],
      '#default_value' => $this->configuration['selection_type'] ?? 'single',
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * Gets the element type based on configuration.
   */
  protected function getElementType(): string {
    $selection_type = $this->configuration['selection_type'] ?? 'single';
    return $selection_type === 'multiple' ? 'checkboxes' : 'radios';
  }

  /**
   * {@inheritdoc}
   */
  public function getScore(Answer $answer): float {
    $answers = $answer->getActivity()->get('answers');
    $data = $answer->getData()['answer'];
    if (!\is_array($data)) {
      $data = [$data];
    }

    $data = \array_filter($data, static fn($item) => $item !== 0);
    $score = 0;
    $max_score = 0;
    foreach ($answers as $delta => $answer_item) {
      if (\in_array((string) $delta, $data, TRUE)) {
        $answer = TRUE;
      }
      else {
        $answer = FALSE;
      }

      \assert($answer_item instanceof LmsAnswer);
      if ($answer_item->isCorrect()) {
        $max_score++;
        // Checking the correct answer +1.
        if ($answer) {
          $score++;
        }
        // Not checking the correct answer 0.
      }
      // Checking an incorrect answer -1.
      elseif ($answer) {
        $score--;
      }
    }

    // Result.
    $result = 0;

    // No correct answers - only 0 works.
    if ($max_score === 0) {
      if ($score === 0) {
        $result = 1;
      }
    }
    // More or equal incorrect checked than correct - 0.
    // Calculate fraction otherwise.
    elseif ($score > 0) {
      $result = $score / $max_score;
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function answeringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    $activity = $answer->getActivity();
    $data = $answer->getData();
    $options = [];
    foreach ($activity->get('answers') as $delta => $answer_item) {
      $options[$delta] = $answer_item->get('answer')->getValue();
    }

    $element_type = $this->getElementType();
    $form['answer'] = [
      '#title' => $this->t('Your answer'),
      '#type' => $element_type,
      '#options' => $options,
      '#default_value' => $data['answer'] ?? [],
      '#required' => $element_type === 'radios',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getAnswerRenderable(Answer $answer): array {
    $data = $answer->getData();

    if (!\array_key_exists('answer', $data)) {
      return [];
    }

    $renderable = [
      '#theme' => 'item_list',
      '#items' => [],
    ];

    if (!\is_array($data['answer'])) {
      $data['answer'] = [$data['answer'] => TRUE];
    }
    else {
      $data['answer'] = \array_filter($data['answer'], fn($value) => $value !== 0);
    }

    foreach ($answer->getActivity()->get('answers') as $delta => $answer_item) {
      $renderable['#items'][] = $answer_item->get('answer')->getValue() . ' - ' . (\array_key_exists($delta, $data['answer']) ? $this->t('Selected') : $this->t('Not selected'));
    }
    return $renderable;
  }

}
