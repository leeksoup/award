<?php

declare(strict_types=1);

namespace Drupal\lms_free_text_model\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
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
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state): array {
    $parents = $form['#parents'];
    $field_name = $this->fieldDefinition->getName();
    $state = static::getWidgetState($parents, $field_name, $form_state);
    if (!isset($state['rubric_visible_items'])) {
      $state['rubric_visible_items'] = min(5, max(3, count($items)));
      static::setWidgetState($parents, $field_name, $form_state, $state);
    }
    $visible = $state['rubric_visible_items'];
    $elements = parent::formMultipleElements($items, $form, $form_state);
    // Preserve the storage-level cardinality and core extraction/reordering.
    // Only the number of authoring rows is progressive.
    for ($delta = 0; $delta < 5; $delta++) {
      if ($delta >= $visible) {
        unset($elements[$delta]);
      }
      elseif (isset($elements[$delta]['_weight'])) {
        $elements[$delta]['_weight']['#delta'] = $visible - 1;
      }
    }
    $elements['#max_delta'] = $visible - 1;
    $id_prefix = implode('-', array_merge($parents, [$field_name]));
    $wrapper_id = Html::getUniqueId($id_prefix . '-rubric-wrapper');
    $elements['#prefix'] = '<div id="' . $wrapper_id . '">';
    $elements['#suffix'] = '</div>';
    if ($visible < 5 && !$form_state->isProgrammed()) {
      $elements['add_more'] = [
        '#type' => 'submit',
        '#name' => strtr($id_prefix, '-', '_') . '_add_check',
        '#value' => $this->t('Add another check'),
        '#limit_validation_errors' => [],
        '#submit' => [[static::class, 'addCheckSubmit']],
        '#ajax' => [
          'callback' => [static::class, 'addCheckAjax'],
          'wrapper' => $wrapper_id,
        ],
      ];
    }
    return $elements;
  }

  /**
   * Reveals one more row while keeping five as the server-side maximum.
   */
  public static function addCheckSubmit(array $form, FormStateInterface $form_state): void {
    $button = $form_state->getTriggeringElement();
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
    $state = static::getWidgetState($element['#field_parents'], $element['#field_name'], $form_state);
    $state['rubric_visible_items'] = min(5, ($state['rubric_visible_items'] ?? 3) + 1);
    static::setWidgetState($element['#field_parents'], $element['#field_name'], $form_state, $state);
    $form_state->setRebuild();
  }

  /**
   * Replaces the widget, including its button when the limit is reached.
   */
  public static function addCheckAjax(array $form, FormStateInterface $form_state): array {
    $button = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
  }

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
