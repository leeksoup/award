<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Collects question bundles routed to fallback plugin handling.
 *
 * @MigrateProcessPlugin(
 *   id = "anu_to_lms_fallback_bundles"
 * )
 */
final class AnuToLmsFallbackBundles extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): array {
    if (!is_array($value)) {
      return [];
    }

    $fallback = (string) ($this->configuration['fallback_plugin'] ?? 'custom_or_nearest');
    $bundles = [];

    foreach ($value as $item) {
      if (!is_array($item)) {
        continue;
      }
      if (($item['plugin_id'] ?? NULL) !== $fallback) {
        continue;
      }
      $bundle = (string) ($item['question_bundle'] ?? 'unknown');
      $bundles[$bundle] = $bundle;
    }

    return array_values($bundles);
  }

}
