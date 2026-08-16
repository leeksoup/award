<?php

declare(strict_types=1);

namespace Drupal\Tests\lms\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\group\Entity\Group;
use Drupal\lms\Entity\Answer;
use Drupal\lms\Entity\CourseStatus;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\Entity\LessonStatus;
use PHPUnit\Framework\Attributes\Group as AttributesGroup;

/**
 * Tests lesson status deletion.
 */
#[AttributesGroup('lms')]
final class QueueDeletionTest extends KernelTestBase {

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
   * Testing lesson statuses.
   *
   * @var \Drupal\lms\Entity\LessonStatus[]
   */
  protected array $lessonStatus = [];

  /**
   * Testing answers.
   *
   * @var \Drupal\lms\Entity\Answer[][]
   */
  protected array $answer = [];

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
    $this->installEntitySchema('lms_course_status');
    $this->installEntitySchema('lms_lesson_status');
    $this->installEntitySchema('lms_answer');
  }

  /**
   * Test entity deletion.
   */
  public function testDeletion(): void {
    // User deletion case.
    $course = Group::create([
      'type' => 'lms_course',
      'name' => 'Test course',
      'uid' => $this->createUser(),
    ]);
    $course->save();
    $student = $this->createUser();
    $courseStatus = CourseStatus::create([
      CourseStatusInterface::COURSE_FIELD => $course->id(),
      'uid' => $student,
    ]);
    $courseStatus->save();

    // Create lesson statuses and answers based on the course status.
    $this->createContent($courseStatus);

    $student->delete();
    $reloaded = CourseStatus::load($courseStatus->id());
    self::assertInstanceOf(CourseStatus::class, $reloaded);
    $this->assertContentExists();

    // Content is deleted by cron.
    $this->container->get('cron')->run();
    $reloaded = CourseStatus::load($courseStatus->id());
    self::assertNull($reloaded, 'Course status should not exist.');

    // Assert related lesson statuses and answers were deleted.
    $this->assertContentDeleted();

    // Course deletion case.
    $courseStatus = CourseStatus::create([
      CourseStatusInterface::COURSE_FIELD => $course->id(),
      'uid' => $this->createUser(),
    ]);
    $courseStatus->save();
    $this->createContent($courseStatus);
    $course->delete();
    $reloaded = CourseStatus::load($courseStatus->id());
    self::assertInstanceOf(CourseStatus::class, $reloaded);
    $this->assertContentExists();
    $this->container->get('cron')->run();
    $reloaded = CourseStatus::load($courseStatus->id());
    self::assertNull($reloaded, 'Course status should not exist.');
    $this->assertContentDeleted();
  }

  /**
   * Creates testing lesson statuses ans answers based on a give course status.
   *
   * @param \Drupal\lms\Entity\CourseStatus $courseStatus
   *   The course status.
   */
  private function createContent(CourseStatus $courseStatus): void {
    for ($i = 0; $i < 10; $i++) {
      $this->lessonStatus[$i] = LessonStatus::create([
        'lms_course' => $courseStatus->getCourseId(),
        'course_status' => $courseStatus,
      ]);
      $this->lessonStatus[$i]->save();

      for ($j = 0; $j < 10; $j++) {
        $this->answer[$i][$j] = Answer::create([
          'lesson_status' => $this->lessonStatus[$i]->id(),
        ]);
        $this->answer[$i][$j]->save();
      }
    }
  }

  /**
   * Asserts that testing lesson statuses and answers exist.
   */
  private function assertContentExists(): void {
    for ($i = 0; $i < 10; $i++) {
      $reloaded = LessonStatus::load($this->lessonStatus[$i]->id());
      self::assertInstanceOf(LessonStatus::class, $reloaded);
      self::assertSame($this->lessonStatus[$i]->id(), $reloaded->id());
      for ($j = 0; $j < 10; $j++) {
        $reloaded = Answer::load($this->answer[$i][$j]->id());
        self::assertInstanceOf(Answer::class, $reloaded);
        self::assertSame($this->answer[$i][$j]->id(), $reloaded->id());
      }
    }
  }

  /**
   * Asserts that testing lesson statuses and answers are deleted.
   */
  private function assertContentDeleted(): void {
    for ($i = 0; $i < 10; $i++) {
      self::assertNull(LessonStatus::load($this->lessonStatus[$i]->id()));
      for ($j = 0; $j < 10; $j++) {
        self::assertNull(Answer::load($this->answer[$i][$j]->id()));
      }
    }
  }

}
