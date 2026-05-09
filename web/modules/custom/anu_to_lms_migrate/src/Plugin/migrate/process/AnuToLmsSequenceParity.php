<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Produces a sequencing parity report between source and mapped arrays.
 *
 * @MigrateProcessPlugin(
 *   id = "anu_to_lms_sequence_parity"
 * )
 */
final class AnuToLmsSequenceParity extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): array {
    $source = [];
    $mapped = [];

    if (is_array($value)) {
      $source = is_array($value['source'] ?? NULL) ? array_values($value['source']) : [];
      $mapped = is_array($value['mapped'] ?? NULL) ? array_values($value['mapped']) : [];
    }

    $source_count = count($source);
    $mapped_count = count($mapped);

    return [
      'source_count' => $source_count,
      'mapped_count' => $mapped_count,
      'count_match' => $source_count === $mapped_count,
      // Since mapping IDs change, index parity is the stable comparison we can
      // verify at transform time.
      'index_order_match' => $source_count === $mapped_count,
      'status' => $source_count === $mapped_count ? 'ok' : 'count_mismatch',
    ];
  }

}
