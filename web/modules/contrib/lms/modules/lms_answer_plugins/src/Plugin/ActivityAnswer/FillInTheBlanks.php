<?php

declare(strict_types=1);

namespace Drupal\lms_answer_plugins\Plugin\ActivityAnswer;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Attribute\ActivityAnswer;
use Drupal\lms_answer_plugins\Plugin\DragAndDropBase;

/**
 * Fill in the blanks activity plugin (drag and drop).
 */
#[ActivityAnswer(
  id: 'fill_in_the_blanks',
  name: new TranslatableMarkup('Fill in the Blanks (Drag and Drop)')
)]
final class FillInTheBlanks extends DragAndDropBase {
  // Logic is inherited from DragAndDropBase.
}
