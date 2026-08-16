<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin\ActivityAnswer;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Attribute\ActivityAnswer;
use Drupal\lms_answer_plugins\Plugin\TrueFalseBase;

/**
 * Long Answer activity plugin.
 */
#[ActivityAnswer(
  id: 'true_false',
  name: new TranslatableMarkup('True / false question'),
)]
final class TrueFalse extends TrueFalseBase {
  // No changes to base class.
}
