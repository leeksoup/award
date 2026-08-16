<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\Views;

/**
 * Filter that shows only LMS entities not referenced by any parent entity.
 *
 * Uses a LEFT JOIN + IS NULL pattern for efficient orphan detection.
 * The deleted = 0 condition is placed in the JOIN ON clause (not WHERE)
 * to preserve the LEFT JOIN semantics — putting it in WHERE would silently
 * convert the LEFT JOIN into an INNER JOIN and miss the orphaned rows.
 *
 * Required definition keys (set in LmsEntityViewsDataProvider):
 *   - parent_table:  The field storage table that holds the parent reference.
 *                    (e.g. 'group__lessons', 'lms_lesson__activities')
 *   - parent_column: The child-ID column in that table.
 *                    (e.g. 'lessons_target_id', 'activities_target_id')
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter(id: 'lms_orphan')]
final class LmsOrphanFilter extends FilterPluginBase {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['value']['default'] = 1;
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state): void {
    $form['value'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Only orphaned (not attached to any parent)'),
      '#default_value' => $this->value ?? 1,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary(): mixed {
    return $this->t('only orphaned');
  }

  /**
   * {@inheritdoc}
   *
   * Adds a LEFT JOIN on the parent reference table and a WHERE IS NULL
   * condition so only entities with no matching parent row are returned.
   * When exposed and unchecked (value = 0), the filter is a no-op.
   */
  public function query(): void {
    if (!(bool) $this->value) {
      return;
    }

    $parent_table = (string) ($this->definition['parent_table'] ?? '');
    $parent_column = (string) ($this->definition['parent_column'] ?? '');

    $this->ensureMyTable();

    $configuration = [
      'type' => 'LEFT',
      'table' => $parent_table,
      'field' => $parent_column,
      'left_table' => $this->tableAlias,
      'left_field' => $this->realField,
      'extra' => [
        ['field' => 'deleted', 'value' => 0, 'numeric' => TRUE],
      ],
    ];
    $join = Views::pluginManager('join')->createInstance('standard', $configuration);
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $alias = $query->addTable($parent_table, $this->relationship, $join);
    $query->addWhereExpression($this->options['group'], "$alias.entity_id IS NULL");
  }

}
