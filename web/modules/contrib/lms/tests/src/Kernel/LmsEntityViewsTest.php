<?php

declare(strict_types=1);

namespace Drupal\Tests\lms\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\group\Entity\Group;
use Drupal\lms\Entity\Activity;
use Drupal\lms\Entity\ActivityType;
use Drupal\lms\Entity\Lesson;
use Drupal\lms\Entity\LessonStatus;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Tests Views integration for LMS entities.
 *
 * Covers:
 *   - LmsParentEntityFilter: activities by direct parent lesson.
 *   - LmsParentEntityFilter: activities by grandparent course (two-hop).
 *   - LmsOrphanFilter: activities with no parent lesson.
 *   - LmsOrphanFilter: lessons with no parent course.
 *   - Lesson status → lesson relationship via lms_revision_reference field.
 */
#[TestGroup('lms')]
final class LmsEntityViewsTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'filter',
    'field',
    'flexible_permissions',
    'group',
    'lms',
    'options',
    'text',
    'user',
    'views',
  ];

  /**
   * Holds all test entities keyed by label (e.g. 'lesson1', 'course2').
   *
   * Also holds 'adminUser' for bypassing the uid access filter.
   */
  private array $testEntityData = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('action');
    $this->installConfig(['group']);
    $this->installConfig(['lms']);
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('view');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installEntitySchema('lms_lesson');
    $this->installEntitySchema('lms_activity');
    $this->installEntitySchema('lms_lesson_status');

    // Write all test view configs directly to the active config storage.
    $config_dir = \dirname(\dirname(__DIR__)) . '/config/activities_filter';
    foreach ([
      'views.view.test_activities_filter',
      'views.view.test_lms_lesson_filter',
      'views.view.test_lesson_status_relationship',
    ] as $config_id) {
      $this->container->get('config.storage')->write(
        $config_id,
        Yaml::decode(\file_get_contents("$config_dir/$config_id.yml")),
      );
    }

    $this->setUpContent();
  }

  /**
   * Tests filtering activities by their direct parent lesson.
   */
  public function testFilterByLesson(): void {
    $names = $this->executeView('parent_lesson', [$this->testEntityData['lesson1']->id()]);
    self::assertContains('Activity 1', $names);
    self::assertContains('Activity 2', $names);
    self::assertNotContains('Activity 3', $names);
    self::assertNotContains('Activity 4', $names);

    $names = $this->executeView('parent_lesson', [$this->testEntityData['lesson2']->id()]);
    self::assertNotContains('Activity 1', $names);
    self::assertNotContains('Activity 2', $names);
    self::assertContains('Activity 3', $names);
    self::assertContains('Activity 4', $names);
  }

  /**
   * Tests filtering activities by their grandparent course.
   */
  public function testFilterByCourse(): void {
    // Course 1 has lesson1 + lesson2 → all four activities.
    $names = $this->executeView('parent_course', [$this->testEntityData['course1']->id()]);
    self::assertContains('Activity 1', $names);
    self::assertContains('Activity 2', $names);
    self::assertContains('Activity 3', $names);
    self::assertContains('Activity 4', $names);

    // Course 2 has lesson2 only → activities 3 and 4.
    $names = $this->executeView('parent_course', [$this->testEntityData['course2']->id()]);
    self::assertNotContains('Activity 1', $names);
    self::assertNotContains('Activity 2', $names);
    self::assertContains('Activity 3', $names);
    self::assertContains('Activity 4', $names);
  }

  /**
   * Tests that the orphan filter returns only activities with no parents.
   */
  public function testOrphanedActivities(): void {
    $names = $this->executeOrphanView('test_activities_filter');
    self::assertContains('Orphan Activity', $names);
    self::assertNotContains('Activity 1', $names);
    self::assertNotContains('Activity 2', $names);
    self::assertNotContains('Activity 3', $names);
    self::assertNotContains('Activity 4', $names);
  }

  /**
   * Tests that the orphan filter returns only lessons without a parent course.
   */
  public function testOrphanedLessons(): void {
    $names = $this->executeOrphanView('test_lms_lesson_filter');
    self::assertContains('Orphan Lesson', $names);
    self::assertNotContains('Lesson 1', $names);
    self::assertNotContains('Lesson 2', $names);
  }

  /**
   * Tests that the lesson_revision relationship joins to the correct lesson.
   *
   * This verifies that LessonStatusViewsData correctly exposes
   * lesson_revision__target_id as a working standard relationship, enabling
   * views of lesson statuses to reach lesson fields via LESSON_FIELD.
   */
  public function testLessonStatusToLessonRelationship(): void {
    $admin = $this->createUser(['administer lms']);

    $lesson1 = Lesson::create(['name' => 'Lesson Status Test Lesson 1', 'status' => 1, 'uid' => $admin->id()]);
    $lesson1->save();
    $lesson2 = Lesson::create(['name' => 'Lesson Status Test Lesson 2', 'status' => 1, 'uid' => $admin->id()]);
    $lesson2->save();

    $status1 = LessonStatus::create([LessonStatusInterface::LESSON_FIELD => $lesson1->id()]);
    $status1->save();
    $status2 = LessonStatus::create([LessonStatusInterface::LESSON_FIELD => $lesson2->id()]);
    $status2->save();

    $this->setCurrentUser($admin);
    $view = Views::getView('test_lesson_status_relationship');
    $view->setDisplay('default');
    $view->execute();

    self::assertCount(2, $view->result, 'View returns one row per lesson status with a matched lesson.');

    $lessonLabels = \array_map(
      static fn($row) => $row->_relationship_entities['lesson_revision__target_id']->label(),
      $view->result,
    );

    self::assertContains('Lesson Status Test Lesson 1', $lessonLabels);
    self::assertContains('Lesson Status Test Lesson 2', $lessonLabels);
  }

  /**
   * Creates all test entities.
   */
  private function setUpContent(): void {
    $this->testEntityData['adminUser'] = $this->createUser(['administer lms']);
    $uid = $this->testEntityData['adminUser']->id();

    ActivityType::create(['id' => 'test', 'name' => 'Test', 'pluginId' => 'no_answer'])->save();

    foreach (['Activity 1', 'Activity 2', 'Activity 3', 'Activity 4'] as $name) {
      $activity = Activity::create(['type' => 'test', 'name' => $name, 'status' => 1, 'uid' => $uid]);
      $activity->save();
      $this->testEntityData[\strtolower(\str_replace(' ', '', $name))] = $activity;
    }

    // Orphaned activity: intentionally not added to any lesson.
    $orphanActivity = Activity::create(['type' => 'test', 'name' => 'Orphan Activity', 'status' => 1, 'uid' => $uid]);
    $orphanActivity->save();
    $this->testEntityData['orphanActivity'] = $orphanActivity;

    $this->testEntityData['lesson1'] = Lesson::create(['name' => 'Lesson 1', 'status' => 1, 'uid' => $uid]);
    $this->testEntityData['lesson1']->get('activities')->appendItem(['target_id' => $this->testEntityData['activity1']->id()]);
    $this->testEntityData['lesson1']->get('activities')->appendItem(['target_id' => $this->testEntityData['activity2']->id()]);
    $this->testEntityData['lesson1']->save();

    $this->testEntityData['lesson2'] = Lesson::create(['name' => 'Lesson 2', 'status' => 1, 'uid' => $uid]);
    $this->testEntityData['lesson2']->get('activities')->appendItem(['target_id' => $this->testEntityData['activity3']->id()]);
    $this->testEntityData['lesson2']->get('activities')->appendItem(['target_id' => $this->testEntityData['activity4']->id()]);
    $this->testEntityData['lesson2']->save();

    // Orphaned lesson: intentionally not added to any course.
    $orphanLesson = Lesson::create(['name' => 'Orphan Lesson', 'status' => 1, 'uid' => $uid]);
    $orphanLesson->save();
    $this->testEntityData['orphanLesson'] = $orphanLesson;

    $this->testEntityData['course1'] = Group::create(['type' => 'lms_course', 'label' => 'Course 1', 'uid' => $uid]);
    $this->testEntityData['course1']->get('lessons')->appendItem(['target_id' => $this->testEntityData['lesson1']->id()]);
    $this->testEntityData['course1']->get('lessons')->appendItem(['target_id' => $this->testEntityData['lesson2']->id()]);
    $this->testEntityData['course1']->save();

    $this->testEntityData['course2'] = Group::create(['type' => 'lms_course', 'label' => 'Course 2', 'uid' => $uid]);
    $this->testEntityData['course2']->get('lessons')->appendItem(['target_id' => $this->testEntityData['lesson2']->id()]);
    $this->testEntityData['course2']->save();
  }

  /**
   * Executes the activities view with one parent-entity filter active.
   *
   * Handlers are initialized first so values can be set directly on the filter
   * objects. initHandlers() sets the inited flag, preventing re-initialization
   * during execute(), so our direct assignments are preserved.
   *
   * @param string $filter_id
   *   The filter ID as defined in the view config (e.g. 'parent_lesson').
   * @param list<mixed> $entity_ids
   *   Entity IDs to filter by.
   *
   * @return list<string>
   *   Labels of the activity entities in the result.
   */
  private function executeView(string $filter_id, array $entity_ids): array {
    $view = Views::getView('test_activities_filter');
    $view->setDisplay('default');
    $view->initHandlers();

    // Set values directly on the initialized filter handlers. Mark all filters
    // as non-exposed so acceptExposedInput() returns TRUE immediately without
    // touching $this->value.
    foreach ($view->filter as $id => $filter) {
      $filter->options['exposed'] = FALSE;
      $filter->value = ($id === $filter_id) ? $entity_ids : [];
    }

    $this->setCurrentUser($this->testEntityData['adminUser']);
    $view->execute();

    return \array_map(
      static fn($row) => $row->_entity->label(),
      $view->result,
    );
  }

  /**
   * Executes the given view with only the orphan filter active (value = 1).
   *
   * @param string $view_name
   *   Machine name of the view to execute.
   *
   * @return list<string>
   *   Labels of the entities in the result.
   */
  private function executeOrphanView(string $view_name): array {
    $view = Views::getView($view_name);
    $view->setDisplay('default');
    $view->initHandlers();

    foreach ($view->filter as $id => $filter) {
      $filter->options['exposed'] = FALSE;
      $filter->value = ($id === 'orphaned') ? 1 : [];
    }

    $this->setCurrentUser($this->testEntityData['adminUser']);
    $view->execute();

    return \array_map(
      static fn($row) => $row->_entity->label(),
      $view->result,
    );
  }

}
