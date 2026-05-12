<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Builds a parity summary for achievements milestone semantics.
 *
 * @MigrateProcessPlugin(
 *   id = "anu_to_lms_milestone_parity"
 * )
 */
final class AnuToLmsMilestoneParity extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): array {
    $source = is_array($value) ? $value : [];

    $lesson_source = (int) ($source['lesson_source_count'] ?? 0);
    $lesson_migrated = (int) ($source['lesson_migrated_count'] ?? 0);
    $section_source = (int) ($source['section_source_count'] ?? 0);
    $section_migrated = (int) ($source['section_migrated_count'] ?? 0);
    $course_source = (int) ($source['course_source_count'] ?? 0);
    $course_migrated = (int) ($source['course_migrated_count'] ?? 0);

    $lesson_match = $lesson_source === $lesson_migrated;
    $section_match = $section_source === $section_migrated;
    $course_match = $course_source === $course_migrated;

    return [
      'lesson' => ['source' => $lesson_source, 'migrated' => $lesson_migrated, 'match' => $lesson_match],
      'section' => ['source' => $section_source, 'migrated' => $section_migrated, 'match' => $section_match],
      'course' => ['source' => $course_source, 'migrated' => $course_migrated, 'match' => $course_match],
      'overall_status' => ($lesson_match && $section_match && $course_match) ? 'ok' : 'mismatch',
    ];
  }

}
