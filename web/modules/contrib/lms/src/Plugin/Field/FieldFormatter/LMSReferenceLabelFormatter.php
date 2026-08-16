<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the 'entity reference label' formatter.
 */
#[FieldFormatter(
  id: 'lms_reference_label',
  label: new TranslatableMarkup('Label'),
  description: new TranslatableMarkup('Display labels of the referenced entities'),
  field_types: ['lms_reference'],
)]
final class LMSReferenceLabelFormatter extends EntityReferenceLabelFormatter {

}
