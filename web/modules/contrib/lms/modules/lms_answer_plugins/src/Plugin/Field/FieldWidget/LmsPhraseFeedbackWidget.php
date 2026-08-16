<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the 'lms_phrase_feedback' widget.
 */
#[FieldWidget(
  id: 'lms_phrase_feedback',
  label: new TranslatableMarkup('Default'),
  field_types: ['lms_phrase_feedback'],
)]
final class LmsPhraseFeedbackWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\lms_answer_plugins\Plugin\Field\FieldType\LmsPhraseFeedback */
    $item = $items[$delta];

    // Require at least one set of phrase match and feedback.
    $is_required = $delta === 0;
    $required_description = $is_required ? ' ' . $this->t('Required for the first phrase match.') : '';

    $element['phrase'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Match Phrase'),
      '#description' => $this->t('A phrase that students can be expected to include in their answer. Use the pipe symbol (|) to separate variations of the same concept (e.g., right answer|correct answer).'),
      '#default_value' => $item->get('phrase')->getValue() ?? '',
      '#size' => 60,
      '#placeholder' => $this->t('Enter match phrase (alternative matches can be separated with | symbol)'),
      '#required' => $is_required,
    ];

    $element['feedback_present'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Feedback if phrase is present'),
      '#description' => $this->t('Feedback to show when the phrase is found in the student answer.') . $required_description,
      '#default_value' => $item->get('feedback_present')->getValue() ?? '',
      '#rows' => 3,
      '#placeholder' => $this->t('Positive reinforcement to give the student for bringing up this concept'),
      '#required' => $is_required,
    ];

    $element['feedback_absent'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Feedback if phrase is absent'),
      '#description' => $this->t('Feedback to show when the phrase is NOT found in the student answer.') . $required_description,
      '#default_value' => $item->get('feedback_absent')->getValue() ?? '',
      '#rows' => 3,
      '#placeholder' => $this->t('Suggestions for the student to look at this concept from another angle'),
      '#required' => $is_required,
    ];

    return $element;
  }

  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$item) {
      // Only extract the 'value' from the array, ignore the format.
      if (\array_key_exists('feedback_present', $item) && \is_array($item['feedback_present'])) {
        $item['feedback_present'] = $item['feedback_present']['value'];
      }
      if (\array_key_exists('feedback_absent', $item) && \is_array($item['feedback_absent'])) {
        $item['feedback_absent'] = $item['feedback_absent']['value'];
      }
    }
    return $values;
  }

}
