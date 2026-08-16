<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\views\filter;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter that narrows results by an ancestor entity.
 *
 * Required definition key (set in getViewsData()):
 *   - ancestor_entity_type: The entity type ID of the ancestor to filter by.
 *     Supported values and their behavior depend on the view's base table:
 *
 *     'group' on lms_lesson_field_data
 *       Single hop: group__lessons → lesson IDs.
 *       (Filter lessons by their parent lms_course group.)
 *
 *     'lms_lesson' on lms_activity_field_data
 *       Single hop: lms_lesson__activities → activity IDs.
 *       (Filter activities by their parent lesson.)
 *
 *     'group' on lms_activity_field_data
 *       Two hops: group__lessons → lesson IDs,
 *                 then lms_lesson__activities → activity IDs.
 *       (Filter activities by their grandparent lms_course group.)
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter(id: 'lms_parent_entity')]
final class LmsParentEntityFilter extends InOperator {

  /**
   * Stores validated exposed input (entity IDs).
   *
   * @var array|null
   */
  public ?array $validatedExposedInput = NULL;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions(): ?array {
    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state): void {
    $ancestor = (string) ($this->definition['ancestor_entity_type'] ?? '');
    $value = \is_array($this->value) ? $this->value : [];
    $entities = $value !== []
      ? $this->entityTypeManager->getStorage($ancestor)->loadMultiple($value)
      : [];

    $form['value'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->valueTitle,
      '#target_type' => $ancestor,
      '#tags' => TRUE,
      '#default_value' => \array_values($entities),
      '#process_default_value' => FALSE,
    ];

    if ($ancestor === 'group') {
      $form['value']['#selection_settings']['target_bundles'] = ['lms_course' => 'lms_course'];
    }
  }

  /**
   * {@inheritdoc}
   *
   * Extracts entity IDs from the tags-style autocomplete value and stores them
   * as a flat array so that query() and adminSummary() receive plain IDs.
   */
  protected function valueValidate($form, FormStateInterface $form_state): void {
    $ids = [];
    foreach ((array) $form_state->getValue(['options', 'value']) as $value) {
      $ids[] = $value['target_id'];
    }
    $form_state->setValue(['options', 'value'], $ids);
  }

  /**
   * {@inheritdoc}
   */
  public function acceptExposedInput($input): bool {
    if (($this->options['exposed'] ?? FALSE) !== TRUE) {
      return TRUE;
    }

    $use_operator = (bool) ($this->options['expose']['use_operator'] ?? FALSE);
    $operator_id = (string) ($this->options['expose']['operator_id'] ?? '');
    if ($use_operator && $operator_id !== '' && \array_key_exists($operator_id, $input)) {
      $this->operator = $input[$operator_id];
    }

    if ($this->view->is_attachment && $this->view->display_handler->usesExposed()) {
      $this->validatedExposedInput = (array) $this->view->exposed_raw_input[$this->options['expose']['identifier']];
    }

    if (($this->options['expose']['required'] ?? FALSE) !== TRUE && $this->validatedExposedInput === NULL) {
      return FALSE;
    }

    $rc = parent::acceptExposedInput($input);
    if ($rc && $this->validatedExposedInput !== NULL) {
      $this->value = $this->validatedExposedInput;
    }

    return $rc;
  }

  /**
   * {@inheritdoc}
   *
   * Extracts entity IDs from the tags-style autocomplete value for exposed
   * filters and stores them in $validatedExposedInput.
   */
  public function validateExposed(&$form, FormStateInterface $form_state): void {
    if (($this->options['exposed'] ?? FALSE) !== TRUE) {
      return;
    }

    $identifier = $this->options['expose']['identifier'];
    $input = $form_state->getValue($identifier);

    $is_grouped = (bool) ($this->options['is_grouped'] ?? FALSE);
    $group_items = (array) ($this->options['group_info']['group_items'] ?? []);
    if ($is_grouped && \is_scalar($input) && \array_key_exists((string) $input, $group_items)) {
      $this->validatedExposedInput = $group_items[(string) $input]['value'];
      return;
    }

    foreach ((array) $form_state->getValue($identifier) as $value) {
      $this->validatedExposedInput[] = $value['target_id'];
    }
  }

  /**
   * {@inheritdoc}
   *
   * Intentionally empty: prevent InOperator::valueSubmit() from running
   * array_filter on our value array (mirrors TaxonomyIndexTid).
   */
  protected function valueSubmit($form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function adminSummary(): mixed {
    // Populate $this->valueOptions with entity labels so the parent summary
    // can render them (mirrors TaxonomyIndexTid::adminSummary()).
    $this->valueOptions = [];
    $value = \is_array($this->value) ? \array_filter($this->value) : [];
    if ($value !== []) {
      $storage = $this->entityTypeManager->getStorage(
        (string) ($this->definition['ancestor_entity_type'] ?? ''),
      );
      foreach ($storage->loadMultiple($value) as $id => $entity) {
        $this->valueOptions[$id] = $entity->label();
      }
    }
    return parent::adminSummary();
  }

  /**
   * {@inheritdoc}
   *
   * Resolves ancestor entity IDs to target entity IDs via hardcoded table
   * lookups, then adds a WHERE condition on the view's primary key field.
   *
   * The number of hops depends on the ancestor_entity_type definition key
   * and the view's base table:
   *
   * - 'group' on lms_lesson view: one hop (group__lessons).
   * - 'lms_lesson' on lms_activity view: one hop (lms_lesson__activities).
   * - 'group' on lms_activity view: two hops (group → lessons → activities).
   */
  public function query(): void {
    $value = \is_array($this->value) ? $this->value : [];
    if ($value === []) {
      return;
    }

    $ancestor = (string) ($this->definition['ancestor_entity_type'] ?? '');
    $target_ids = $this->resolveTargetIds($ancestor, $value);

    $this->ensureMyTable();
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;

    if ($target_ids === []) {
      if ($this->operator !== 'not in') {
        $query->addWhereExpression($this->options['group'], '1 = 0');
      }
      return;
    }

    $query->addWhere(
      $this->options['group'],
      "$this->tableAlias.$this->realField",
      $target_ids,
      $this->operator === 'not in' ? 'NOT IN' : 'IN',
    );
  }

  /**
   * Resolves ancestor IDs to the IDs of the entities shown in the view.
   *
   * @param string $ancestor
   *   The ancestor_entity_type definition value ('group' or 'lms_lesson').
   * @param list<mixed> $ancestor_ids
   *   The selected ancestor entity IDs.
   *
   * @return list<mixed>
   *   IDs of the target entities to filter by.
   */
  private function resolveTargetIds(string $ancestor, array $ancestor_ids): array {
    if ($ancestor === 'lms_lesson') {
      // Direct parent: lesson → activities.
      return $this->fetchColumn('lms_lesson__activities', 'activities_target_id', $ancestor_ids);
    }

    if ($ancestor === 'group') {
      // First hop: course group → lessons.
      $lesson_ids = $this->fetchColumn('group__lessons', 'lessons_target_id', $ancestor_ids);

      if ($this->table === 'lms_activity_field_data') {
        // Grandparent case: second hop → activities.
        if ($lesson_ids === []) {
          return [];
        }
        return $this->fetchColumn('lms_lesson__activities', 'activities_target_id', $lesson_ids);
      }

      // Direct parent case (lms_lesson view): lessons are the target.
      return $lesson_ids;
    }

    return [];
  }

  /**
   * Fetches a single column from a dedicated field table.
   *
   * @param string $table
   *   The dedicated field storage table name.
   * @param string $column
   *   The target column to fetch (e.g. 'lessons_target_id').
   * @param list<mixed> $entity_ids
   *   Values to match against the entity_id column.
   *
   * @return list<mixed>
   *   Fetched column values.
   */
  private function fetchColumn(string $table, string $column, array $entity_ids): array {
    $select = $this->database->select($table, 't');
    $select->fields('t', [$column]);
    $select->condition('t.entity_id', $entity_ids, 'IN');
    $select->condition('t.deleted', 0);
    $statement = $select->execute();
    /** @var list<mixed> $result */
    $result = $statement !== NULL ? $statement->fetchCol() : [];
    return $result;
  }

}
