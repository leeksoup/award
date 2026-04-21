<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Extracts ordered target IDs from entity-reference style source arrays.
 *
 * @MigrateProcessPlugin(
 *   id = "anu_to_lms_ordered_target_ids"
 * )
 */
final class AnuToLmsOrderedTargetIds extends ProcessPluginBase {

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

    $ids = [];
    foreach ($value as $item) {
      if (!is_array($item)) {
        continue;
      }
      $id = $item['target_id'] ?? NULL;
      if ($id === NULL || $id === '') {
        continue;
      }
      $ids[] = $id;
    }

    return $ids;
  }

}
