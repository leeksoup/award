<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Normalizes assessment scoring metadata for LMS activity set payloads.
 *
 * @MigrateProcessPlugin(
 *   id = "anu_to_lms_assessment_scoring"
 * )
 */
final class AnuToLmsAssessmentScoring extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): array {
    $source = is_array($value) ? $value : [];

    $max_score = (float) ($source['max_score'] ?? 0);
    $pass_percent = (float) ($source['pass_percentage'] ?? $source['pass_percent'] ?? 80);
    $model = (string) ($source['scoring_model'] ?? 'sum_correct');

    if ($pass_percent < 0) {
      $pass_percent = 0;
    }
    if ($pass_percent > 100) {
      $pass_percent = 100;
    }

    return [
      'max_score' => $max_score,
      'pass_percentage' => $pass_percent,
      'scoring_model' => $model,
      'allow_retry' => (bool) ($source['allow_retry'] ?? TRUE),
    ];
  }

}
