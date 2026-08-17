<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\process;

use Drupal\Component\Utility\Html;
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

    $items = $value['items'] ?? $value[0] ?? [];
    $resources = $value['resources'] ?? $value[1] ?? [];
    if (!is_array($items)) {
      $items = [];
    }
    if (!is_array($resources)) {
      $resources = [];
    }

    usort($items, static function (mixed $a, mixed $b): int {
      $a_delta = is_array($a) && isset($a['delta']) ? (int) $a['delta'] : 0;
      $b_delta = is_array($b) && isset($b['delta']) ? (int) $b['delta'] : 0;
      return $a_delta <=> $b_delta;
    });

    $parts = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $text = trim((string) ($item['text'] ?? ''));
      if ($text === '') {
        continue;
      }
      $description = trim((string) ($item['description'] ?? ''));
      // Source values are formatted HTML and commonly include a block-level
      // <p>. Keep that markup directly inside the list item rather than
      // producing invalid <strong><p>...</p></strong> nesting.
      $line = $text;
      if ($description !== '') {
        $line .= '<div>' . $description . '</div>';
      }
      $parts[] = '<li>' . $line . '</li>';
    }

    foreach ($this->buildResourceItems($resources) as $resource_item) {
      $parts[] = $resource_item;
    }

    if ($parts === []) {
      return [];
    }

    return [[
      'value' => '<ul class="anu-checklist">' . implode('', $parts) . '</ul>',
      'format' => 'filtered_html',
    ]];
  }

  /**
   * Builds appended checklist items for student resource documents.
   */
  private function buildResourceItems(array $resources): array {
    $items = [];
    foreach ($resources as $resource) {
      if (!is_array($resource)) {
        continue;
      }

      $label = trim((string) ($resource['label'] ?? ''));
      $url = trim((string) ($resource['url'] ?? ''));
      if ($label === '' || $url === '') {
        continue;
      }

      $line = '<a href="' . Html::escape($url) . '">'
        . Html::escape($label)
        . '</a>';
      $description = trim((string) ($resource['description'] ?? ''));
      if ($description !== '') {
        $line .= ': ' . Html::escape($description);
      }
      $items[] = '<li class="anu-checklist-resource">' . $line . '</li>';
    }

    return $items;
  }

}
