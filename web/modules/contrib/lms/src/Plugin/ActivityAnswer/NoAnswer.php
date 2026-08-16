<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\ActivityAnswer;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Attribute\ActivityAnswer;
use Drupal\lms\Plugin\ActivityAnswerBase;

/**
 * Long Answer activity plugin.
 */
#[ActivityAnswer(
  id: 'no_answer',
  name: new TranslatableMarkup('No answer'),
)]
final class NoAnswer extends ActivityAnswerBase {
  // Nothing to define here in addition to the base class methods.
}
