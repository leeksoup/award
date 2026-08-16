<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Renders a component from a computed field's view() method.
 */
#[FieldFormatter(
  id: 'lms_component_formatter',
  label: new TranslatableMarkup('LMS Component'),
  field_types: ['string'],
)]
final class LMSComponentFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    // We let the custom FieldItemList class's view() method build the render
    // array, as it has the full logic for the component.
    return $items->view();
  }

}
