<?php

declare(strict_types=1);

namespace Drupal\Tests\lms\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\lms\LmsKernelTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\group\Entity\Group;
use Drupal\lms\Entity\Activity;
use Drupal\lms\Entity\ActivityType;
use Drupal\lms\Entity\Lesson;
use Drupal\lms\Entity\LessonStatus;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\Plugin\views\field\CourseProgress;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Tests Views integration for LMS entities.
 *
 * Covers:
 *   - LmsParentEntityFilter: activities by direct parent lesson.
 *   - LmsParentEntityFilter: activities by grandparent course (two-hop).
 *   - LmsOrphanFilter: activities with no parent lesson.
 *   - LmsOrphanFilter: lessons with no parent course.
 *   - Lesson status → lesson relationship via lms_revision_reference field.
 *   - CourseProgress field: finished / total lessons, percentage and display.
 */
#[TestGroup('lms')]
#[RunTestsInSeparateProcesses]
final class LmsEntityViewsTest extends KernelTestBase {

  use LmsKernelTestTrait;
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
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['group']);
    $this->installConfig(['lms']);
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('view');
    $this->installEntitySchema('lms_lesson');
    $this->installEntitySchema('lms_activity');
    $this->installEntitySchema('lms_course_status');
    $this->installEntitySchema('lms_lesson_status');

    // Write all test view configs directly to the active config storage.
    $config_dir = \dirname(\dirname(__DIR__)) . '/config/views';
    foreach ([
      'views.view.test_activities_filter',
      'views.view.test_lms_lesson_filter',
      'views.view.test_lesson_status_relationship',
      'views.view.test_course_progress',
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
   * Tests the course progress field.
   *
   * The scenarios share one (expensive) kernel setUp and a single view
   * execution per display mode. Covered, per course status:
   *   - finished / total ratio, percentage and progress bar rendering;
   *   - batching of per-row data across several course statuses;
   *   - a lesson finished more than once counted only once;
   *   - an unfinished lesson status not counted;
   *   - compact display mode (plain label, no progress bar);
   *   - a course with no lessons rendering 0 / 0 without dividing by zero;
   *   - the percentage capping at 100 when completed exceeds the total;
   *   - the list cache tags exposed to the view render cache.
   */
  public function testCourseProgress(): void {
    $uid = (int) $this->testEntityData['adminUser']->id();
    [$courseA, $lessonsA] = $this->createCourse('Course A', 3, $uid);
    [$courseB, $lessonsB] = $this->createCourse('Course B', 2, $uid);
    [$compactCourse, $compactLessons] = $this->createCourse('Compact Course', 4, $uid);
    [$cappedCourse, $cappedLessons] = $this->createCourse('Capped Course', 1, $uid);

    // Course A: two finished statuses for one lesson (counted once) plus an
    // unfinished status (not counted) → 2 of 3 lessons.
    $statusA = $this->createCourseStatus($courseA, $uid);
    $this->finishLesson($statusA, $lessonsA[0]);
    $this->finishLesson($statusA, $lessonsA[1]);
    $this->finishLesson($statusA, $lessonsA[1]);
    $this->startLesson($statusA, $lessonsA[2]);

    // Course B: 1 of 2 lessons.
    $statusB = $this->createCourseStatus($courseB, $uid);
    $this->finishLesson($statusB, $lessonsB[0]);

    // Compact course: 1 of 4 lessons, asserted in compact mode below.
    $compactStatus = $this->createCourseStatus($compactCourse, $uid);
    $this->finishLesson($compactStatus, $compactLessons[0]);

    // Course saved without lessons, bypassing the NotNull constraint.
    $emptyCourse = Group::create(['type' => 'lms_course', 'label' => 'Empty Course', 'uid' => $uid]);
    $emptyCourse->save();
    $emptyStatus = $this->createCourseStatus($emptyCourse, $uid);

    // Capped course: a finished lesson no longer part of the course pushes
    // completed (2) above the current total (1); the percentage caps at 100.
    $cappedStatus = $this->createCourseStatus($cappedCourse, $uid);
    $this->finishLesson($cappedStatus, $cappedLessons[0]);
    $strayLesson = Lesson::create(['name' => 'Stray Lesson', 'status' => 1, 'uid' => $uid]);
    $strayLesson->save();
    $this->finishLesson($cappedStatus, $strayLesson);

    // Full display mode: one execution renders every status as a progress bar.
    $full = $this->renderProgress();

    self::assertStringContainsString('class="progress"', $full[$statusA->id()], 'Course A: rendered as a progress bar.');
    self::assertStringContainsString('2 / 3 Lessons', $full[$statusA->id()], 'Course A: duplicate + unfinished lessons ignored.');
    self::assertStringContainsString('67%', $full[$statusA->id()], 'Course A: 2 of 3 is 67%.');

    self::assertStringContainsString('1 / 2 Lessons', $full[$statusB->id()], 'Course B: 1 of 2 lessons.');
    self::assertStringContainsString('50%', $full[$statusB->id()], 'Course B: 1 of 2 is 50%.');

    self::assertStringContainsString('0 / 0 Lessons', $full[$emptyStatus->id()], 'Empty course: 0 of 0 lessons.');
    self::assertStringContainsString('0%', $full[$emptyStatus->id()], 'Empty course: no division by zero.');

    self::assertStringContainsString('2 / 1 Lessons', $full[$cappedStatus->id()], 'Capped course: completed exceeds total.');
    self::assertStringContainsString('100%', $full[$cappedStatus->id()], 'Capped course: percentage caps at 100.');

    // Compact display mode: plain label, no progress bar.
    $compact = $this->renderProgress('compact')[$compactStatus->id()];
    self::assertStringNotContainsString('class="progress"', $compact, 'Compact mode: no progress bar theme.');
    self::assertStringContainsString('1 / 4 Lessons', $compact, 'Compact mode: plain label markup.');

    // List cache tags exposed to the view render cache via
    // CacheableDependencyInterface (invalidated on any status/course change).
    $view = Views::getView('test_course_progress');
    $view->setDisplay('default');
    $view->initHandlers();
    $field = $view->field['progress'];
    \assert($field instanceof CourseProgress);
    self::assertEqualsCanonicalizing(
      ['lms_lesson_status_list', 'lms_course_status_list', 'group_list:course'],
      $field->getCacheTags(),
    );
    self::assertSame([], $field->getCacheContexts());
    self::assertSame(Cache::PERMANENT, $field->getCacheMaxAge());
  }

  /**
   * Executes the course progress view and renders the progress field per row.
   *
   * Views calls preRender() once for the whole result set before rendering each
   * row, so this mirrors that order to exercise the batched aggregate queries.
   *
   * @param string $display_mode
   *   The 'display_mode' field option: 'full' or 'compact'.
   *
   * @return array<int|string, string>
   *   The progress field rendered HTML keyed by course status id.
   */
  private function renderProgress(string $display_mode = 'full'): array {
    $this->setCurrentUser($this->testEntityData['adminUser']);

    // We need request render context for this to work.
    $request = Request::create('/');
    $request->setSession(new Session());
    $this->container->get(RequestStack::class)->push($request);
    $renderer = $this->container->get(RendererInterface::class);

    $view = Views::getView('test_course_progress');
    $view->setDisplay('default');
    $view->execute();

    $field = $view->field['progress'];
    \assert($field instanceof CourseProgress);
    $field->options['display_mode'] = $display_mode;
    $field->preRender($view->result);

    $rendered = [];
    foreach ($view->result as $row) {
      $rendered[$row->_entity->id()] = (string) $renderer->executeInRenderContext(new RenderContext(), fn () => $field->render($row));
    }

    return $rendered;
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
