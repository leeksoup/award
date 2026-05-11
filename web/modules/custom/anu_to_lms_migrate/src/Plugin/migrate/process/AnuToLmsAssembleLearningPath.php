<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Builds ordered LMS learning-path payload from migrated module IDs.
 *
 * @MigrateProcessPlugin(
 *   id = "anu_to_lms_assemble_learning_path"
 * )
 */
final class AnuToLmsAssembleLearningPath extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): array {
    if (!is_array($value)) {
      return [];
    }

    $path = [];
    foreach (array_values($value) as $position => $module_id) {
      if ($module_id === NULL || $module_id === '') {
        continue;
      }
      $path[] = [
        'position' => $position,
        'module_id' => $module_id,
      ];
    }

    return $path;
  }

}
