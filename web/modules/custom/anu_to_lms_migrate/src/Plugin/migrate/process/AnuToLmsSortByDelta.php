<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Sorts a source array by delta while preserving original item payload.
 *
 * @MigrateProcessPlugin(
 *   id = "anu_to_lms_sort_by_delta"
 * )
 */
final class AnuToLmsSortByDelta extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): array {
    if (!is_array($value)) {
      return [];
    }

    usort($value, static function (mixed $a, mixed $b): int {
      $a_delta = is_array($a) && isset($a['delta']) ? (int) $a['delta'] : 0;
      $b_delta = is_array($b) && isset($b['delta']) ? (int) $b['delta'] : 0;
      return $a_delta <=> $b_delta;
    });

    return $value;
  }

}
