<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Maps ordered assessment item payloads to LMS plugin-compatible payloads.
 *
 * @MigrateProcessPlugin(
 *   id = "anu_to_lms_map_assessment_items"
 * )
 */
final class AnuToLmsMapAssessmentItems extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): array {
    if (!is_array($value)) {
      return [];
    }

    $fallback = (string) ($this->configuration['fallback_plugin'] ?? 'custom_or_nearest');
    $map = [
      'question_single_choice' => 'select',
      'question_multi_choice' => 'select',
      'question_short_answer' => 'free_text',
      'question_long_answer' => 'free_text',
      'question_scale' => $fallback,
      'question_likert_scale' => $fallback,
    ];

    usort($value, static function (mixed $a, mixed $b): int {
      $a_delta = is_array($a) && isset($a['delta']) ? (int) $a['delta'] : 0;
      $b_delta = is_array($b) && isset($b['delta']) ? (int) $b['delta'] : 0;
      return $a_delta <=> $b_delta;
    });

    $result = [];
    foreach ($value as $item) {
      if (!is_array($item)) {
        continue;
      }
      $bundle = (string) ($item['bundle'] ?? $item['question_bundle'] ?? '');
      $result[] = [
        'target_id' => $item['target_id'] ?? NULL,
        'delta' => (int) ($item['delta'] ?? 0),
        'question_bundle' => $bundle,
        'plugin_id' => $map[$bundle] ?? $fallback,
      ];
    }

    return $result;
  }

}
