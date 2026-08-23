<?php

declare(strict_types=1);

namespace Drupal\anu_lms_decommission\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Audits and decommissions Anu LMS after a verified content migration.
 */
final class AnuLmsDecommissionCommands extends DrushCommands {

  /**
   * Anu modules in reverse dependency order.
   */
  private const ANU_MODULES = [
    'anu_lms_demo_content',
    'anu_lms_search',
    'anu_lms_assessments',
    'anu_lms_permissions',
    'anu_lms',
  ];

  /**
   * Migration maps that prove target content was created before purging source.
   */
  private const TARGET_MIGRATIONS = [
    'anu_to_lms_paragraph_lesson_checklists' => 'lms_activity',
    'anu_to_lms_paragraph_lesson_sections' => 'lms_activity',
    'anu_to_lms_paragraph_assessment_questions' => 'lms_activity',
    'anu_to_lms_node_module_lessons' => 'lms_lesson',
    'anu_to_lms_node_module_assessments' => 'lms_lesson',
    'anu_to_lms_node_courses' => 'group',
  ];

  /**
   * Config whose generic names can be shared by unrelated site features.
   *
   * The Anu LMS distribution ships a reusable Document media type. It may
   * predate Anu LMS on a site and can still be used by migrated resources or
   * unrelated content, so it must not be treated as Anu-exclusive.
   */
  private const SHARED_CONFIG_PREFIXES = [
    'core.entity_form_mode.node.embedded',
    'core.entity_form_display.media.document.',
    'core.entity_view_display.media.document.',
    'field.field.media.document.field_media_document',
    'field.storage.media.field_media_document',
    'image.style.',
    'media.type.document',
  ];

  /**
   * Constructs the command service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StorageInterface $configStorage,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleInstallerInterface $moduleInstaller,
    private readonly Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Reports Anu source data, configuration, and migration-map readiness.
   */
  #[CLI\Command(name: 'anu-lms-decommission:audit', aliases: ['ald:audit'])]
  #[CLI\Usage(name: 'drush anu-lms-decommission:audit', description: 'Report Anu LMS content and config that must be handled before uninstall.')]
  public function audit(): int {
    $inventory = $this->inventory();
    $this->printInventory($inventory);
    return $this->hasPreflightBlockers($inventory) ? 1 : 0;
  }

  /**
   * Writes a timestamped, non-destructive decommission inventory archive.
   */
  #[CLI\Command(name: 'anu-lms-decommission:archive', aliases: ['ald:archive'])]
  #[CLI\Option(name: 'directory', description: 'Existing writable directory outside the web root for the JSON inventory.')]
  #[CLI\Usage(name: 'drush anu-lms-decommission:archive --directory=/srv/backups/anu-lms', description: 'Write the Anu LMS inventory manifest before taking database and files backups.')]
  public function archive(array $options = ['directory' => NULL]): void {
    $directory = (string) ($options['directory'] ?? '');
    if ($directory === '' || !is_dir($directory) || !is_writable($directory)) {
      throw new \InvalidArgumentException('The --directory option must name an existing writable directory outside the web root.');
    }

    $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
      . 'anu-lms-decommission-inventory-' . gmdate('Ymd-His') . '.json';
    $encoded = json_encode($this->inventory(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $encoded . PHP_EOL) === FALSE) {
      throw new \RuntimeException(sprintf('Could not write %s.', $path));
    }

    $this->logger()->success(\dt('Wrote Anu LMS inventory manifest to @path.', ['@path' => $path]));
    $this->logger()->warning(\dt('This command does not replace a database dump and protected files backup. Complete both before a destructive command.'));
  }

  /**
   * Deletes verified Anu source entities while preserving managed files.
   */
  #[CLI\Command(name: 'anu-lms-decommission:purge-content', aliases: ['ald:purge-content'])]
  #[CLI\Option(name: 'confirm', description: 'Required literal value: PURGE-ANU-SOURCE.')]
  #[CLI\Usage(name: 'drush anu-lms-decommission:purge-content --confirm=PURGE-ANU-SOURCE', description: 'Delete Anu source nodes, paragraphs, terms, and checklist results after target acceptance.')]
  public function purgeContent(array $options = ['confirm' => NULL]): void {
    $this->assertConfirmation((string) ($options['confirm'] ?? ''), 'PURGE-ANU-SOURCE');
    $inventory = $this->inventory();
    $this->assertTargetMapsReady($inventory);

    $deleted = [];
    $deleted['nodes'] = $this->deleteBundles('node', $inventory['source_node_bundles']);
    $deleted['paragraphs'] = $this->deleteBundles('paragraph', $inventory['source_paragraph_bundles']);
    $deleted['terms'] = $this->deleteBundles('taxonomy_term', $inventory['source_vocabularies'], 'vid');
    $deleted['checklist_results'] = $this->deleteAll('lesson_checklist_result');

    $this->io()->table(
      ['Entity type', 'Deleted'],
      array_map(static fn (string $type, int $count): array => [$type, (string) $count], array_keys($deleted), $deleted),
    );
    $this->logger()->warning(\dt('Managed files were intentionally retained. Review the archive manifest before deleting any file entities.'));
  }

  /**
   * Removes Anu-exclusive configuration after the source data is gone.
   */
  #[CLI\Command(name: 'anu-lms-decommission:remove-config', aliases: ['ald:remove-config'])]
  #[CLI\Option(name: 'confirm', description: 'Required literal value: REMOVE-ANU-CONFIG.')]
  #[CLI\Option(name: 'include-shared', description: 'Also remove shared candidates, including image styles, the embedded node form mode, and Document media configuration, after operator review.')]
  #[CLI\Usage(name: 'drush anu-lms-decommission:remove-config --confirm=REMOVE-ANU-CONFIG', description: 'Remove Anu-exclusive active configuration and preserve shared candidates.')]
  public function removeConfig(array $options = ['confirm' => NULL, 'include-shared' => FALSE]): void {
    $this->assertConfirmation((string) ($options['confirm'] ?? ''), 'REMOVE-ANU-CONFIG');
    $inventory = $this->inventory();
    if ($inventory['source_entity_count'] > 0) {
      throw new \RuntimeException('Anu source entities remain. Run audit, archive, and purge-content before removing configuration.');
    }

    $include_shared = !empty($options['include-shared']);
    $removed = [];
    $skipped = [];

    $active_names = $this->activeAnuConfigNames();
    $this->removeFieldConfig($active_names, $removed, $skipped, $include_shared);

    foreach ($active_names as $name) {
      if (str_starts_with($name, 'field.field.') || str_starts_with($name, 'field.storage.')) {
        continue;
      }
      if (!$include_shared && $this->isSharedConfigCandidate($name)) {
        $skipped[] = [$name, 'shared candidate'];
        continue;
      }
      $this->configFactory->getEditable($name)->delete();
      $removed[] = [$name];
    }

    if ($removed !== []) {
      $this->io()->table(['Removed active config'], $removed);
    }
    if ($skipped !== []) {
      $this->io()->table(['Preserved config', 'Reason'], $skipped);
    }
    $this->logger()->success(\dt('Removed @count Anu configuration objects.', ['@count' => count($removed)]));
  }

  /**
   * Uninstalls enabled Anu modules after source and config cleanup.
   */
  #[CLI\Command(name: 'anu-lms-decommission:uninstall', aliases: ['ald:uninstall'])]
  #[CLI\Option(name: 'confirm', description: 'Required literal value: UNINSTALL-ANU-LMS.')]
  #[CLI\Usage(name: 'drush anu-lms-decommission:uninstall --confirm=UNINSTALL-ANU-LMS', description: 'Uninstall enabled Anu LMS modules after a clean audit.')]
  public function uninstall(array $options = ['confirm' => NULL]): void {
    $this->assertConfirmation((string) ($options['confirm'] ?? ''), 'UNINSTALL-ANU-LMS');
    $inventory = $this->inventory();
    if ($inventory['source_entity_count'] > 0) {
      throw new \RuntimeException('Anu source entities remain. Refusing to invoke Anu LMS uninstall hooks.');
    }

    $modules = $inventory['enabled_anu_modules'];
    if ($modules === []) {
      $this->logger()->success(\dt('No Anu LMS modules are enabled.'));
      return;
    }

    foreach (self::ANU_MODULES as $module) {
      if (in_array($module, $modules, TRUE)) {
        $this->moduleInstaller->uninstall([$module], FALSE);
      }
    }
    $this->logger()->success(\dt('Uninstalled: @modules.', ['@modules' => implode(', ', $modules)]));
  }

  /**
   * Verifies that Anu modules, source entities, and owned config are gone.
   */
  #[CLI\Command(name: 'anu-lms-decommission:verify', aliases: ['ald:verify'])]
  #[CLI\Usage(name: 'drush anu-lms-decommission:verify', description: 'Verify post-uninstall Anu LMS removal while retaining target LMS migration config.')]
  public function verify(): int {
    $inventory = $this->inventory();
    $this->printInventory($inventory);

    $exclusive_config = array_filter($inventory['active_anu_config'], fn (string $name): bool => !$this->isSharedConfigCandidate($name));
    $issues = $inventory['enabled_anu_modules'] !== []
      || $inventory['source_entity_count'] > 0
      || $exclusive_config !== []
      || $inventory['external_anu_dependencies'] !== [];
    if ($issues) {
      $this->logger()->warning(\dt('Anu LMS decommission is incomplete.'));
      return 1;
    }
    $this->logger()->success(\dt('No enabled Anu modules, Anu source entities, or Anu-exclusive active config were detected.'));
    return 0;
  }

  /**
   * Builds one read-only source and target inventory.
   */
  private function inventory(): array {
    $node_bundles = $this->bundleIds('node.type.');
    $paragraph_bundles = $this->bundleIds('paragraphs.paragraphs_type.');
    $vocabularies = $this->bundleIds('taxonomy.vocabulary.');
    $source_counts = [
      'nodes' => $this->countBundles('node', $node_bundles),
      'paragraphs' => $this->countBundles('paragraph', $paragraph_bundles),
      'terms' => $this->countBundles('taxonomy_term', $vocabularies, 'vid'),
      'checklist_results' => $this->countAll('lesson_checklist_result'),
    ];

    return [
      'enabled_anu_modules' => array_values(array_filter(self::ANU_MODULES, $this->moduleHandler->moduleExists(...))),
      'source_node_bundles' => $node_bundles,
      'source_paragraph_bundles' => $paragraph_bundles,
      'source_vocabularies' => $vocabularies,
      'source_counts' => $source_counts,
      'source_entity_count' => array_sum($source_counts),
      'source_file_ids' => $this->sourceFileIds($node_bundles, $paragraph_bundles),
      'active_anu_config' => $this->activeAnuConfigNames(),
      'external_anu_dependencies' => $this->externalAnuDependencies(),
      'target_migration_maps' => $this->targetMigrationMaps(),
    ];
  }

  /**
   * Returns Anu config names shipped by the core module and its submodules.
   */
  private function sourceConfigNames(): array {
    $names = [];
    foreach (self::ANU_MODULES as $module) {
      if (!$this->moduleExtensionList->exists($module)) {
        continue;
      }
      $path = $this->moduleExtensionList->getPath($module);
      foreach (['install', 'optional'] as $directory) {
        $storage = new FileStorage($path . '/config/' . $directory);
        foreach ($storage->listAll() as $name) {
          $names[$name] = $name;
        }
      }
    }
    ksort($names);
    return array_values($names);
  }

  /**
   * Returns Anu config names including known Group 3-renamed variants.
   */
  private function sourceConfigCandidates(): array {
    $names = [];
    foreach ($this->sourceConfigNames() as $name) {
      $names[$name] = $name;
      $converted = str_replace([
        'group.content_type.',
        'field.storage.group_content.',
        'field.field.group_content.',
        'core.entity_form_display.group_content.',
        'core.entity_view_display.group_content.',
      ], [
        'group.relationship_type.',
        'field.storage.group_relationship.',
        'field.field.group_relationship.',
        'core.entity_form_display.group_relationship.',
        'core.entity_view_display.group_relationship.',
      ], $name);
      $names[$converted] = $converted;
    }
    ksort($names);
    return array_values($names);
  }

  /**
   * Returns active Anu config, including Group 3-renamed variants.
   */
  private function activeAnuConfigNames(): array {
    return array_values(array_filter($this->sourceConfigCandidates(), $this->configStorage->exists(...)));
  }

  /**
   * Returns source bundle IDs whose config is shipped by Anu modules.
   */
  private function bundleIds(string $prefix): array {
    $ids = [];
    foreach ($this->sourceConfigNames() as $name) {
      if (str_starts_with($name, $prefix)) {
        $ids[] = substr($name, strlen($prefix));
      }
    }
    sort($ids);
    return array_values(array_unique($ids));
  }

  /**
   * Counts entities in the supplied bundles.
   */
  private function countBundles(string $entity_type, array $bundles, string $bundle_key = 'type'): int {
    if ($bundles === [] || !$this->entityTypeManager->hasDefinition($entity_type)) {
      return 0;
    }
    return (int) $this->entityTypeManager->getStorage($entity_type)->getQuery()
      ->accessCheck(FALSE)
      ->condition($bundle_key, $bundles, 'IN')
      ->count()
      ->execute();
  }

  /**
   * Counts every entity of an optional entity type.
   */
  private function countAll(string $entity_type): int {
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      return 0;
    }
    return (int) $this->entityTypeManager->getStorage($entity_type)->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Deletes entities in source bundles in storage-safe batches.
   */
  private function deleteBundles(string $entity_type, array $bundles, string $bundle_key = 'type'): int {
    if ($bundles === [] || !$this->entityTypeManager->hasDefinition($entity_type)) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition($bundle_key, $bundles, 'IN')->execute();
    return $this->deleteIds($entity_type, $ids);
  }

  /**
   * Deletes every entity from an optional entity type.
   */
  private function deleteAll(string $entity_type): int {
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage($entity_type);
    return $this->deleteIds($entity_type, $storage->getQuery()->accessCheck(FALSE)->execute());
  }

  /**
   * Deletes an ID list in bounded chunks.
   */
  private function deleteIds(string $entity_type, array $ids): int {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $deleted = 0;
    foreach (array_chunk($ids, 50, TRUE) as $chunk) {
      $entities = $storage->loadMultiple($chunk);
      if ($entities !== []) {
        $storage->delete($entities);
        $deleted += count($entities);
      }
    }
    return $deleted;
  }

  /**
   * Deletes Anu field instances and exclusive storage through Field API.
   */
  private function removeFieldConfig(array $names, array &$removed, array &$skipped, bool $include_shared): void {
    if (!$this->entityTypeManager->hasDefinition('field_config') || !$this->entityTypeManager->hasDefinition('field_storage_config')) {
      return;
    }

    $field_config_storage = $this->entityTypeManager->getStorage('field_config');
    foreach ($names as $name) {
      if (!str_starts_with($name, 'field.field.')) {
        continue;
      }
      if (!$include_shared && $this->isSharedConfigCandidate($name)) {
        $skipped[] = [$name, 'shared candidate'];
        continue;
      }
      $id = substr($name, strlen('field.field.'));
      $field = $field_config_storage->load($id);
      if ($field !== NULL) {
        $field->delete();
        $removed[] = [$name];
      }
    }

    $field_storage_storage = $this->entityTypeManager->getStorage('field_storage_config');
    foreach ($names as $name) {
      if (!str_starts_with($name, 'field.storage.')) {
        continue;
      }
      if (!$include_shared && $this->isSharedConfigCandidate($name)) {
        $skipped[] = [$name, 'shared candidate'];
        continue;
      }
      $data = $this->configStorage->read($name) ?: [];
      $entity_type = (string) ($data['entity_type'] ?? '');
      $field_name = (string) ($data['field_name'] ?? '');
      if ($entity_type === '' || $field_name === '') {
        $skipped[] = [$name, 'missing field metadata'];
        continue;
      }
      if ($this->hasRemainingFieldInstances($entity_type, $field_name)) {
        $skipped[] = [$name, 'used by a non-Anu bundle'];
        continue;
      }
      $id = substr($name, strlen('field.storage.'));
      $field_storage = $field_storage_storage->load($id);
      if ($field_storage !== NULL) {
        $field_storage->delete();
        $removed[] = [$name];
      }
    }
  }

  /**
   * Checks whether any field instance still uses a storage definition.
   */
  private function hasRemainingFieldInstances(string $entity_type, string $field_name): bool {
    foreach ($this->configStorage->listAll('field.field.' . $entity_type . '.') as $name) {
      $data = $this->configStorage->read($name) ?: [];
      if (($data['field_name'] ?? NULL) === $field_name) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reports file IDs referenced by Anu source nodes and paragraphs.
   */
  private function sourceFileIds(array $node_bundles, array $paragraph_bundles): array {
    $ids = [];
    foreach ([['node', $node_bundles], ['paragraph', $paragraph_bundles]] as [$entity_type, $bundles]) {
      if ($bundles === [] || !$this->entityTypeManager->hasDefinition($entity_type)) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $entity_ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundles, 'IN')->execute();
      foreach ($storage->loadMultiple($entity_ids) as $entity) {
        foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
          if (($definition->getSetting('target_type') ?? NULL) !== 'file' || $entity->get($field_name)->isEmpty()) {
            continue;
          }
          foreach ($entity->get($field_name)->getValue() as $item) {
            if (!empty($item['target_id'])) {
              $ids[(int) $item['target_id']] = (int) $item['target_id'];
            }
          }
        }
      }
    }
    ksort($ids);
    return array_values($ids);
  }

  /**
   * Finds external active config that still explicitly depends on Anu config.
   */
  private function externalAnuDependencies(): array {
    $owned = array_flip(array_filter(
      $this->sourceConfigCandidates(),
      fn (string $name): bool => !$this->isSharedConfigCandidate($name),
    ));
    $matches = [];
    foreach ($this->configStorage->listAll() as $name) {
      if (isset($owned[$name])) {
        continue;
      }
      $data = $this->configStorage->read($name) ?: [];
      $modules = $data['dependencies']['module'] ?? [];
      $config = $data['dependencies']['config'] ?? [];
      $module_match = array_filter($modules, static fn (string $module): bool => str_starts_with($module, 'anu_lms'));
      $config_match = array_filter($config, static fn (string $dependency): bool => isset($owned[$dependency]));
      if ($module_match !== [] || $config_match !== []) {
        $matches[$name] = [
          'modules' => array_values($module_match),
          'config' => array_values($config_match),
        ];
      }
    }
    ksort($matches);
    return $matches;
  }

  /**
   * Summarizes destination migration-map health without bootstrapping Migrate.
   */
  private function targetMigrationMaps(): array {
    $rows = [];
    foreach (self::TARGET_MIGRATIONS as $migration => $destination_entity_type) {
      $table = 'migrate_map_' . $migration;
      if (!$this->database->schema()->tableExists($table)) {
        $rows[$migration] = ['table' => FALSE, 'mapped' => 0, 'missing_destination' => 0, 'missing_entity' => 0];
        continue;
      }
      $query = $this->database->select($table, 'm');
      $destination_ids = $this->database->select($table, 'm')
        ->fields('m', ['destid1'])
        ->isNotNull('destid1')
        ->execute()
        ->fetchCol();
      $existing_ids = $destination_ids !== [] && $this->entityTypeManager->hasDefinition($destination_entity_type)
        ? $this->entityTypeManager->getStorage($destination_entity_type)->getQuery()
          ->accessCheck(FALSE)
          ->condition('id', $destination_ids, 'IN')
          ->execute()
        : [];
      $rows[$migration] = [
        'table' => TRUE,
        'mapped' => (int) $query->countQuery()->execute()->fetchField(),
        'missing_destination' => (int) $this->database->select($table, 'm')
          ->isNull('destid1')
          ->countQuery()
          ->execute()
          ->fetchField(),
        'missing_entity' => count($destination_ids) - count($existing_ids),
      ];
    }
    return $rows;
  }

  /**
   * Requires all present maps to have destinations before source deletion.
   */
  private function assertTargetMapsReady(array $inventory): void {
    $problems = [];
    $source_course_count = $this->countBundles('node', ['course']);
    foreach ($inventory['target_migration_maps'] as $migration => $row) {
      if (!$row['table']) {
        if ($migration === 'anu_to_lms_node_courses' && $source_course_count > 0) {
          $problems[] = $migration . ' map table is missing';
        }
        continue;
      }
      if ($row['missing_destination'] > 0) {
        $problems[] = $migration . ' has ' . $row['missing_destination'] . ' rows without a destination';
      }
      if ($row['missing_entity'] > 0) {
        $problems[] = $migration . ' has ' . $row['missing_entity'] . ' missing destination entities';
      }
      if ($migration === 'anu_to_lms_node_courses' && $row['mapped'] < $source_course_count) {
        $problems[] = $migration . ' maps only ' . $row['mapped'] . ' of ' . $source_course_count . ' source courses';
      }
    }
    if ($problems !== []) {
      throw new \RuntimeException('Target migration maps are not ready: ' . implode('; ', $problems) . '.');
    }
  }

  /**
   * Prints the inventory in compact operational tables.
   */
  private function printInventory(array $inventory): void {
    $this->io()->table(
      ['Source item', 'Count'],
      array_map(static fn (string $name, int $count): array => [$name, (string) $count], array_keys($inventory['source_counts']), $inventory['source_counts']),
    );
    $this->io()->table(
      ['Migration map', 'Rows', 'Missing destinations', 'Missing entities'],
      array_map(static fn (string $name, array $row): array => [$name, $row['table'] ? (string) $row['mapped'] : 'missing table', (string) $row['missing_destination'], (string) $row['missing_entity']], array_keys($inventory['target_migration_maps']), $inventory['target_migration_maps']),
    );
    if ($inventory['enabled_anu_modules'] !== []) {
      $this->io()->note('Enabled Anu modules: ' . implode(', ', $inventory['enabled_anu_modules']));
    }
    if ($inventory['active_anu_config'] !== []) {
      $this->io()->note(sprintf('Active Anu-shipped config objects: %d.', count($inventory['active_anu_config'])));
    }
    if ($inventory['source_file_ids'] !== []) {
      $this->io()->note(sprintf('Source-referenced file IDs retained by purge-content: %d.', count($inventory['source_file_ids'])));
    }
    if ($inventory['external_anu_dependencies'] !== []) {
      $this->io()->warning('External config dependencies require review: ' . implode(', ', array_keys($inventory['external_anu_dependencies'])));
    }
  }

  /**
   * Identifies conditions that must be addressed before destructive work.
   */
  private function hasPreflightBlockers(array $inventory): bool {
    $source_course_count = $this->countBundles('node', ['course']);
    foreach ($inventory['target_migration_maps'] as $migration => $row) {
      if ($migration === 'anu_to_lms_node_courses' && !$row['table'] && $source_course_count > 0) {
        return TRUE;
      }
      if ($migration === 'anu_to_lms_node_courses' && $row['mapped'] < $source_course_count) {
        return TRUE;
      }
      if ($row['missing_destination'] > 0 || $row['missing_entity'] > 0) {
        return TRUE;
      }
    }
    return $inventory['external_anu_dependencies'] !== [];
  }

  /**
   * Checks whether a shipped config name needs explicit shared-resource review.
   */
  private function isSharedConfigCandidate(string $name): bool {
    foreach (self::SHARED_CONFIG_PREFIXES as $prefix) {
      if (str_starts_with($name, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Requires an explicit literal confirmation for destructive commands.
   */
  private function assertConfirmation(string $actual, string $expected): void {
    if ($actual !== $expected) {
      throw new \InvalidArgumentException(sprintf('Pass --confirm=%s to run this destructive command.', $expected));
    }
  }

}
