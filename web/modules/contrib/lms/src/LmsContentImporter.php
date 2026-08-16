<?php

declare(strict_types=1);

namespace Drupal\lms;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\lms\Config\PluginConfigInstaller;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Event\QaContentEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Provides document fetching commands.
 */
final class LmsContentImporter {

  public const ENTITY_TYPE_MAPPING = [
    'users' => 'user',
    'activities' => 'lms_activity',
    'activity_types' => 'lms_activity_type',
    'lessons' => 'lms_lesson',
    'courses' => 'group',
  ];

  public function __construct(
    private readonly ActivityAnswerManager $activityAnswerManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PluginConfigInstaller $pluginConfigInstaller,
    private readonly PasswordGeneratorInterface $passwordGenerator,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Install activity types from activity_types.yml configuration.
   */
  public function installActivityTypes(string $activity_type_provider): void {
    $storage = $this->entityTypeManager->getStorage('lms_activity_type');

    // Load activity types configuration from YAML file.
    $source_directory = $this->moduleExtensionList->getPath($activity_type_provider) . '/tests/data/';
    $activity_types_file = $source_directory . 'activity_types.yml';

    $activity_types_data = Yaml::decode(\file_get_contents($activity_types_file));

    $definitions = $this->activityAnswerManager->getDefinitions();
    foreach ($activity_types_data as $activity_type_data) {
      $activity_type = $storage->load($activity_type_data['id']);
      // Could be already installed from existing config.
      if ($activity_type !== NULL) {
        continue;
      }

      $activity_type = $storage->create([
        'id' => $activity_type_data['id'],
        'name' => $activity_type_data['name'],
        'description' => $activity_type_data['description'],
        'pluginId' => $activity_type_data['pluginId'],
        'pluginConfiguration' => $activity_type_data['pluginConfiguration'],
        'defaultMaxScore' => $activity_type_data['defaultMaxScore'],
      ]);
      $activity_type->save();

      // Install per-bundle plugin config (field instances, displays, etc.).
      // installOptionalConfig() is idempotent for shared configs like field
      // storage, so each bundle must be processed individually even if two
      // bundles share the same pluginId.
      if (\array_key_exists($activity_type_data['pluginId'], $definitions)) {
        $this->pluginConfigInstaller->install($definitions[$activity_type_data['pluginId']], $activity_type->id());
      }
    }
  }

  /**
   * Get QA data from module's tests/data directory.
   */
  public function getData(string $module): array {
    $source_directory = $this->moduleExtensionList->getPath($module) . '/tests/data/';
    $dh = \opendir($source_directory);
    if ($dh === FALSE) {
      throw new \InvalidArgumentException(sprintf("Directory %s doesn't exist or is not readable.", $source_directory));
    }

    $data = [];
    while (FALSE !== ($entry = \readdir($dh))) {
      $path = $source_directory . $entry;
      if (\pathinfo($path, PATHINFO_EXTENSION) !== 'yml') {
        continue;
      }
      $entity_type_id = \pathinfo($path, PATHINFO_FILENAME);
      if (\array_key_exists($entity_type_id, self::ENTITY_TYPE_MAPPING)) {
        $entity_type_id = self::ENTITY_TYPE_MAPPING[$entity_type_id];
      }
      $data[$entity_type_id] = Yaml::decode(\file_get_contents($path));
    }

    // Reorder so there are no missing reference issues.
    $reordered_data = [];
    foreach (self::ENTITY_TYPE_MAPPING as $entity_type_id) {
      if (!\array_key_exists($entity_type_id, $data)) {
        continue;
      }
      $reordered_data[$entity_type_id] = $data[$entity_type_id];
      unset($data[$entity_type_id]);
    }
    foreach ($data as $entity_type_id => $entities_data) {
      $reordered_data[$entity_type_id] = $entities_data;
    }

    return $reordered_data;
  }

  /**
   * Import entities from data array.
   */
  public function import(array $data, array $options = []): void {
    foreach ($data as $entity_type_id => $entities_data) {
      // Handled in self::installActivityTypes().
      if ($entity_type_id === 'lms_activity_type') {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      foreach ($entities_data as $entity_data) {
        if (\count($storage->loadByProperties(['uuid' => $entity_data['uuid']])) !== 0) {
          continue;
        }
        $entity = $storage->create($this->convertData($entity_data, $entity_type_id, $options));
        $entity->save();
      }
    }

    // Allow other modules to act on QA content creation.
    if (\array_key_exists('module', $options)) {
      $event = new QaContentEvent($options['module']);
      $this->eventDispatcher->dispatch($event, $event::NAME);
    }
  }

  /**
   * Test data conversion helper.
   */
  private function convertData(array $input, string $entity_type_id, array $options): array {
    $output = [];

    if ($entity_type_id === 'user') {
      $output = $input;
      $output['pass'] = $options['simple-passwords'] ? '123456' : $this->passwordGenerator->generate();
      $output['status'] = 1;
    }

    elseif ($entity_type_id === 'lms_activity') {
      $output['uuid'] = $input['uuid'];
      $output['uid'] = \array_key_exists('owner_uuid', $input) ? $this->getValueFromUuid('user', $input['owner_uuid']) : $this->currentUser->id();
      $output['type'] = $input['type'];
      $output += $input['values'];
    }

    elseif ($entity_type_id === 'lms_lesson') {
      $output['uuid'] = $input['uuid'];
      $output['uid'] = \array_key_exists('owner_uuid', $input) ? $this->getValueFromUuid('user', $input['owner_uuid']) : $this->currentUser->id();
      $output += $input['values'];

      $output[LessonInterface::ACTIVITIES] = [];
      foreach ($input['activities'] as $activity_data) {
        $target_id = $this->getValueFromUuid('lms_activity', $activity_data['target_uuid']);
        unset($activity_data['target_uuid']);
        $new_activity_data = [
          'target_id' => $target_id,
          'data' => $activity_data,
        ];
        $output[LessonInterface::ACTIVITIES][] = $new_activity_data;
      }
    }

    elseif ($entity_type_id === 'group') {
      $output['uuid'] = $input['uuid'];
      $output['uid'] = \array_key_exists('owner_uuid', $input) ? $this->getValueFromUuid('user', $input['owner_uuid']) : $this->currentUser->id();
      $output['type'] = 'lms_course';
      $output += $input['values'];

      $output[Course::LESSONS] = [];
      foreach ($input['lessons'] as $lesson_data) {
        $target_id = $this->getValueFromUuid('lms_lesson', $lesson_data['target_uuid']);
        unset($lesson_data['target_uuid']);
        $new_lesson_data = [
          'target_id' => $target_id,
          'data' => $lesson_data,
        ];
        $output[Course::LESSONS][] = $new_lesson_data;
      }
    }

    return $output;
  }

  /**
   * Helper method to get entity property from data array.
   */
  private function getValueFromUuid(string $entity_type_id, string $uuid, string $field_name = 'id', ?string $property = NULL): string {
    $entity_storage = $this->entityTypeManager->getStorage($entity_type_id);

    $query = $entity_storage->getQuery();
    $result = $query
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->execute();

    if (\count($result) === 0) {
      throw new \InvalidArgumentException(\sprintf("UUID %s doesn't exist in test data.", $uuid));
    }

    $id = \reset($result);
    if ($field_name === 'id') {
      return $id;
    }
    $entity = $entity_storage->load($id);
    \assert($entity instanceof ContentEntityInterface);
    if (!$entity->hasField($field_name)) {
      throw new \InvalidArgumentException(\sprintf("No %s field on entity.", $field_name));
    }
    $field = $entity->get($field_name);
    if ($field->isEmpty()) {
      throw new \InvalidArgumentException(\sprintf("Empty %s field on entity.", $field_name));
    }
    /** @var \Drupal\Core\Field\FieldItemInterface */
    $item = $field->first();
    if ($property === NULL) {
      $property = $item::mainPropertyName();
    }
    return $item->get($property)->getValue();
  }

}
