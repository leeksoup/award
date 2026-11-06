<?php

declare(strict_types=1);

namespace Drupal\Tests\lms;

use Drupal\group\Entity\Group;
use Drupal\lms\Entity\CourseStatus;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\Entity\Lesson;
use Drupal\lms\Entity\LessonStatus;
use Drupal\lms\Entity\LessonStatusInterface;

/**
 * Builds LMS course, course status and lesson status entities for kernel tests.
 *
 * These helpers create and save entities directly (no UI), so they suit kernel
 * tests. The owner user id is always passed in rather than assumed, so the
 * trait stays independent of any particular test's fixtures.
 */
trait LmsKernelTestTrait {

  /**
   * Creates a course (group) owned by the given user with N lessons.
   *
   * @param string $label
   *   The course label; also used to name its lessons.
   * @param int $lesson_count
   *   Number of lessons to create and attach.
   * @param int $uid
   *   Owner user id, applied to the course and its lessons.
   * @param array<string, mixed> $values
   *   Extra field values for the course, e.g. ['status' => 0] for unpublished.
   *   The type, label and uid above take precedence over these.
   *
   * @return array{0: \Drupal\group\Entity\Group, 1: list<\Drupal\lms\Entity\Lesson>}
   *   The saved course and its lessons in order.
   */
  private function createCourse(string $label, int $lesson_count, int $uid, array $values = []): array {
    $course = Group::create(['type' => 'lms_course', 'label' => $label, 'uid' => $uid] + $values);

    $lessons = [];
    for ($i = 1; $i <= $lesson_count; $i++) {
      $lesson = Lesson::create(['name' => "$label Lesson $i", 'status' => 1, 'uid' => $uid]);
      $lesson->save();
      $lessons[] = $lesson;
      $course->get('lessons')->appendItem(['target_id' => $lesson->id()]);
    }
    $course->save();

    return [$course, $lessons];
  }

  /**
   * Creates a course status for the given course and user.
   */
  private function createCourseStatus(Group $course, int $uid): CourseStatus {
    $status = CourseStatus::create([
      CourseStatusInterface::COURSE_FIELD => $course->id(),
      'uid' => $uid,
    ]);
    $status->save();

    return $status;
  }

  /**
   * Creates a finished lesson status for a lesson under a course status.
   */
  private function finishLesson(CourseStatus $status, Lesson $lesson): LessonStatus {
    $lesson_status = LessonStatus::create([
      'course_status' => $status->id(),
      LessonStatusInterface::LESSON_FIELD => $lesson->id(),
      'finished' => 1000000,
    ]);
    $lesson_status->save();

    return $lesson_status;
  }

  /**
   * Creates an unfinished (started) lesson status under a course status.
   */
  private function startLesson(CourseStatus $status, Lesson $lesson): LessonStatus {
    $lesson_status = LessonStatus::create([
      'course_status' => $status->id(),
      LessonStatusInterface::LESSON_FIELD => $lesson->id(),
    ]);
    $lesson_status->save();

    return $lesson_status;
  }

}
