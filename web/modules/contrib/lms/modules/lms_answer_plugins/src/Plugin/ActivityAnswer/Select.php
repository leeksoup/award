<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin\ActivityAnswer;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Attribute\ActivityAnswer;
use Drupal\lms_answer_plugins\Plugin\SelectBase;

/**
 * Configurable Select activity plugin.
 */
#[ActivityAnswer(
  id: 'select',
  name: new TranslatableMarkup('Select answer(s)'),
)]
final class Select extends SelectBase {

}
