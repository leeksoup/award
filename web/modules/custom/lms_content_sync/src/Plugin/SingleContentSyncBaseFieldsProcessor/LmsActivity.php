<?php

declare(strict_types=1);

namespace Drupal\lms_content_sync\Plugin\SingleContentSyncBaseFieldsProcessor;

use Drupal\lms_content_sync\LmsBaseFieldsProcessor;

/**
 * Exports LMS activities in the shape expected by Single Content Sync.
 *
 * @SingleContentSyncBaseFieldsProcessor(
 *   id = "lms_activity",
 *   label = @Translation("LMS activity base fields processor"),
 *   entity_type = "lms_activity",
 * )
 */
final class LmsActivity extends LmsBaseFieldsProcessor {

}
