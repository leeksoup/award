<?php

declare(strict_types=1);

namespace Drupal\lms_content_sync\Plugin\SingleContentSyncBaseFieldsProcessor;

use Drupal\lms_content_sync\LmsBaseFieldsProcessor;

/**
 * Exports LMS lessons in the shape expected by Single Content Sync.
 *
 * @SingleContentSyncBaseFieldsProcessor(
 *   id = "lms_lesson",
 *   label = @Translation("LMS lesson base fields processor"),
 *   entity_type = "lms_lesson",
 * )
 */
final class LmsLesson extends LmsBaseFieldsProcessor {

}
