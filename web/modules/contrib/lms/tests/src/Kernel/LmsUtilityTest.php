<?php

declare(strict_types=1);

namespace Drupal\Tests\lms\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lms\Controller\CourseController;
use Drupal\lms\Entity\Activity;
use Drupal\lms\Entity\ActivityType;
use Drupal\lms\LmsCourseStorage;
use Drupal\Tests\lms\LmsKernelTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group as AttributesGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests LMS utility behaviors that don't need a full site.
 */
#[AttributesGroup('lms')]
#[RunTestsInSeparateProcesses]
final class LmsUtilityTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('action');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['group']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('view');
  }

  /**
   * Tests a course group type created after the first lookup.
   *
   * Recipes install modules before importing their config entities, so LMS
   * code can run before the lms_course group type exists.
   */
  public function testCourseBundleCreatedAfterLookup(): void {
    $lms_course_storage = $this->container->get(LmsCourseStorage::class);

    self::assertSame([], $lms_course_storage->getCourseBundleIds());
    self::assertSame([], $lms_course_storage->loadGroupRelationshipsByCourseBundles([
      'plugin_id' => 'group_membership',
    ]));

    $this->installConfig(['lms']);

    self::assertSame(['lms_course'], $lms_course_storage->getCourseBundleIds());
    self::assertSame([], $lms_course_storage->loadGroupRelationshipsByCourseBundles([
      'plugin_id' => 'group_membership',
    ]));

    // Course routes rebuilt in the same request are limited to course bundles.
    $this->container->get('router.builder')->rebuild();
    $route = $this->container->get('router.route_provider')->getRouteByName('lms.course.start');
    self::assertSame(['lms_course'], $route->getOption('parameters')['group']['bundle']);
  }

  /**
   * Tests activity page title patterns.
   */
  public function testActivityFormTitle(): void {
    $this->installConfig(['lms']);
    $violations = $this->container->get('config.typed')->get('lms.settings')->validate();
    self::assertCount(0, $violations, (string) $violations);

    $this->installEntitySchema('lms_lesson');
    $this->installEntitySchema('lms_activity');
    $this->installEntitySchema('lms_course_status');
    $this->installEntitySchema('lms_lesson_status');

    $student = $this->createUser();
    $this->setCurrentUser($student);
    $uid = (int) $student->id();

    /** @var \Drupal\lms\Entity\Bundle\Course $course */
    [$course, $lessons] = $this->createCourse('Course', 2, $uid);
    ActivityType::create(['id' => 'test', 'name' => 'Test', 'pluginId' => 'no_answer'])->save();
    $activity_ids = [];
    foreach (['First activity', 'Second activity'] as $name) {
      $activity = Activity::create(['type' => 'test', 'name' => $name, 'status' => 1, 'uid' => $uid]);
      $activity->save();
      $activity_ids[] = $activity->id();
    }
    $lesson_status = $this->startLesson($this->createCourseStatus($course, $uid), $lessons[1]);
    $lesson_status->setActivityIds($activity_ids)->setCurrentActivityDelta(1)->save();

    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(CourseController::class);
    $config = $this->config('lms.settings');

    $lesson_tag = 'lms_lesson:' . $lessons[1]->id();
    $lesson_status_tag = 'lms_lesson_status:' . $lesson_status->id();
    foreach ([
      // Default pattern.
      $config->get('activity_page_title') => ['Course Lesson 2 - Second activity', $lesson_tag],
      '[lms_activity:label]' => ['Second activity', NULL],
      '[lms_lesson_status:lesson_number].[lms_lesson_status:activity_number] [lms_activity:label]' => [
        '2.2 Second activity',
        $lesson_status_tag,
      ],
      'Lesson [lms_lesson_status:lesson_number] - [lms_activity:label]' => [
        'Lesson 2 - Second activity',
        $lesson_status_tag,
      ],
      '[group:title]: [lms_activity:label]' => ['Course: Second activity', NULL],
    ] as $pattern => [$expected, $expected_tag]) {
      $config->set('activity_page_title', $pattern)->save();
      $title = $controller->activityFormTitle($course, 1);
      self::assertIsArray($title);
      self::assertSame($expected, $title['#plain_text'], $pattern);
      self::assertContains('config:lms.settings', $title['#cache']['tags'], $pattern);
      self::assertContains('lms_activity:' . $activity_ids[1], $title['#cache']['tags'], $pattern);
      if ($expected_tag !== NULL) {
        self::assertContains($expected_tag, $title['#cache']['tags'], $pattern);
      }
    }
  }

}
