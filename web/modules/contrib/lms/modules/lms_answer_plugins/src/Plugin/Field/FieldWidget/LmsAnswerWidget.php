<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the 'string_textarea' widget.
 */
#[FieldWidget(
  id: 'lms_answer',
  label: new TranslatableMarkup('Default'),
  field_types: ['lms_answer'],
)]
final class LmsAnswerWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    unset($element['#title']);
    unset($element['#title_display']);
    unset($element['#required']);
    $widget = [];
    $item_value = $items->get($delta)->getValue();
    $widget['answer'] = $element + [
      '#title' => $this->t('Answer'),
      '#type' => 'textarea',
      '#default_value' => $item_value['answer'] ?? '',
      '#rows' => 2,
    ];
    $widget['correct'] = $element + [
      '#title' => $this->t('Correct'),
      '#type' => 'checkbox',
      '#default_value' => $item_value['correct'] ?? FALSE,
    ];

    return $widget;
  }

}
