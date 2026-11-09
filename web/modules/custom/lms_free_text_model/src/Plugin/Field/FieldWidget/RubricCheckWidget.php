<?php

declare(strict_types=1);

namespace Drupal\lms_free_text_model\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Edits a maximum of five repeatable coaching checks.
 */
#[FieldWidget(
  id: 'model_response_rubric_check',
  label: new TranslatableMarkup('Rubric check'),
  field_types: ['model_response_rubric_check'],
)]
final class RubricCheckWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $value = $items[$delta]->getValue();
    $element['label'] = [
      '#type' => 'textfield', '#title' => $this->t('Label (authors only)'),
      '#default_value' => $value['label'] ?? '', '#maxlength' => 128,
    ];
    $element['mode'] = [
      '#type' => 'select', '#title' => $this->t('Pattern type'),
      '#options' => ['phrases' => $this->t('Phrases'), 'regex' => $this->t('Regular expression')],
      '#default_value' => $value['mode'] ?? 'phrases',
    ];
    $element['pattern'] = [
      '#type' => 'textfield', '#title' => $this->t('Pattern'),
      '#default_value' => $value['pattern'] ?? '', '#maxlength' => 1024,
      '#description' => $this->t('Case-insensitive. Phrases use | for alternatives, as in Free Text with Feedback. Regex uses a body without delimiters. A blank pattern is skipped.'),
    ];
    $element['weight'] = [
      '#type' => 'number', '#title' => $this->t('Points weight'),
      '#default_value' => $value['weight'] ?? 1,
      '#min' => 0.000001, '#max' => 1000000, '#step' => 'any',
      '#description' => $this->t('Relative positive weight: use 1 for equal shares, or values such as 2, 1, 1 or 0.5, 0.25, 0.25.'),
    ];
    $element['nudge'] = [
      '#type' => 'textarea', '#title' => $this->t('Nudge text'),
      '#default_value' => $value['nudge'] ?? '', '#rows' => 2,
      '#maxlength' => 2048,
      '#description' => $this->t('Plain text shown to the student when the check does not match.'),
    ];
    return $element;
  }

}
