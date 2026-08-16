<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Builds an LMS activity body from ordered checklist item payloads.
 *
 * @MigrateProcessPlugin(
 *   id = "anu_to_lms_checklist_body",
 *   handle_multiples = TRUE
 * )
 */
final class AnuToLmsChecklistBody extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): array {
    if (!is_array($value) || $value === []) {
      return [];
    }

    usort($value, static function (mixed $a, mixed $b): int {
      $a_delta = is_array($a) && isset($a['delta']) ? (int) $a['delta'] : 0;
      $b_delta = is_array($b) && isset($b['delta']) ? (int) $b['delta'] : 0;
      return $a_delta <=> $b_delta;
    });

    $parts = [];
    foreach ($value as $item) {
      if (!is_array($item)) {
        continue;
      }
      $text = trim((string) ($item['text'] ?? ''));
      if ($text === '') {
        continue;
      }
      $description = trim((string) ($item['description'] ?? ''));
      $line = '<strong>' . $text . '</strong>';
      if ($description !== '') {
        $line .= '<div>' . $description . '</div>';
      }
      $parts[] = '<li>' . $line . '</li>';
    }

    if ($parts === []) {
      return [];
    }

    return [[
      'value' => '<ul class="anu-checklist">' . implode('', $parts) . '</ul>',
      'format' => 'minimal_html',
    ]];
  }

}
