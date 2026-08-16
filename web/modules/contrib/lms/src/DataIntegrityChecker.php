<?php

declare(strict_types=1);

namespace Drupal\lms;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Entity\LessonStatusInterface;

/**
 * Checks Course / Lesson / Activity progress.
 */
final class DataIntegrityChecker {

  use MessengerTrait;
  use StringTranslationTrait;

  /**
   * Are we using entity revisions on statuses?
   */
  private readonly bool $useRevisions;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->useRevisions = $config_factory->get('lms.settings')->get('use_revisions') === TRUE;
  }

  /**
   * Expose setting value to public.
   */
  public function revisionsUsed(): bool {
    return $this->useRevisions;
  }

  /**
   * Get activity usage.
   *
   * @return \Drupal\lms\Entity\LessonInterface[]
   *   Lessons keyed by their IDs.
   */
  public function getActivityUsage(string $activity_id): array {
    $lesson_storage = $this->entityTypeManager->getStorage('lms_lesson');
    $query = $lesson_storage->getQuery();
    $lesson_ids = $query
      ->accessCheck(FALSE)
      ->condition(LessonInterface::ACTIVITIES, $activity_id)
      ->execute();
    /** @var \Drupal\lms\Entity\LessonInterface[] */
    return $lesson_storage->loadMultiple($lesson_ids);
  }

  /**
   * Get lesson usage.
   *
   * @return \Drupal\lms\Entity\Bundle\Course[]
   *   Courses keyed by their IDs.
   */
  public function getLessonUsage(string $lesson_id): array {
    $group_storage = $this->entityTypeManager->getStorage('group');
    $query = $group_storage->getQuery();
    $lesson_ids = $query
      ->accessCheck(FALSE)
      ->condition(Course::LESSONS, $lesson_id)
      ->execute();
    /** @var \Drupal\lms\Entity\Bundle\Course[] */
    return $group_storage->loadMultiple($lesson_ids);
  }

  /**
   * Check if activity has any progress.
   *
   * @return \Drupal\lms\Entity\Bundle\Course[]
   *   Courses keyed by their IDs.
   */
  public function checkActivityProgress(ActivityInterface $activity, bool $use_revision = FALSE): array {
    if ($activity->isNew()) {
      return [];
    }

    $query = $this->entityTypeManager->getStorage('lms_answer')->getAggregateQuery();
    $query->accessCheck(FALSE);
    if ($use_revision) {
      $query->condition(AnswerInterface::ACTIVITY_FIELD . '.vid', $activity->getRevisionId());
    }
    else {
      $query->condition(AnswerInterface::ACTIVITY_FIELD, $activity->id());
    }
    /** @var array<array<string, int>> */
    $results = $query
      ->condition($query->orConditionGroup()
        ->condition('lesson_status.entity:lms_lesson_status.finished', '0')
        ->condition('lesson_status.entity:lms_lesson_status.evaluated', FALSE)
      )
      ->groupBy('lesson_status.entity:lms_lesson_status.course_status')
      ->aggregate('lesson_status.entity:lms_lesson_status.course_status', 'COUNT')
      // Set a range of 50 in case we are dealing with a large dataset.
      ->range(0, 50)

      ->execute();

    if (\count($results) === 0) {
      return [];
    }

    $course_status_ids = \array_map(fn($item) => $item['course_status'], $results);

    /** @var \Drupal\lms\Entity\CourseStatusInterface[] */
    $course_statuses = $this->entityTypeManager->getStorage('lms_course_status')->loadMultiple($course_status_ids);
    $courses = [];
    foreach ($course_statuses as $course_status) {
      $course_id = $course_status->getCourseId();
      if (\array_key_exists($course_id, $courses)) {
        continue;
      }
      $course = $course_status->getCourse();

      // Orphaned course status. It may be there's a data integrity error
      // or this check ran before cleanup cron execution.
      // @see \Drupal\lms\Hook\LmsEntityHooks::groupDelete().
      if ($course === NULL) {
        continue;
      }
      $courses[$course_id] = $course;
    }

    return $courses;
  }

  /**
   * Check if lesson has any progress.
   *
   * @return \Drupal\lms\Entity\Bundle\Course[]
   *   Courses keyed by their IDs.
   */
  public function checkLessonProgress(LessonInterface $lesson, bool $use_revision = FALSE): array {
    if ($lesson->isNew()) {
      return [];
    }

    $query = $this->entityTypeManager->getStorage('lms_lesson_status')->getAggregateQuery();
    $query->accessCheck(FALSE);
    if ($use_revision) {
      $query->condition(LessonStatusInterface::LESSON_FIELD . '.vid', $lesson->getRevisionId());
    }
    else {
      $query->condition(LessonStatusInterface::LESSON_FIELD, $lesson->id());
    }

    /** @var array<array<string, int>> */
    $results = $query
      ->condition($query->orConditionGroup()
        ->condition('finished', '0')
        ->condition('evaluated', FALSE)
      )
      ->groupBy('course_status')
      ->aggregate('course_status', 'COUNT')
      // Set a range of 50 in case we are dealing with a large dataset.
      ->range(0, 50)

      ->execute();

    if (\count($results) === 0) {
      return [];
    }

    $course_status_ids = \array_map(fn($item) => $item['course_status'], $results);

    /** @var \Drupal\lms\Entity\CourseStatusInterface[] */
    $course_statuses = $this->entityTypeManager->getStorage('lms_course_status')->loadMultiple($course_status_ids);
    $courses = [];
    foreach ($course_statuses as $course_status) {
      $course_id = $course_status->getCourseId();
      if (\array_key_exists($course_id, $courses)) {
        continue;
      }
      $course = $course_status->getCourse();

      // Orphaned course status. It may be there's a data integrity error
      // or this check ran before cleanup cron execution.
      // @see \Drupal\lms\Hook\LmsEntityHooks::groupDelete().
      if ($course === NULL) {
        continue;
      }
      $courses[$course_id] = $course;
    }

    return $courses;
  }

  /**
   * Check if the given course has any progress.
   */
  public function checkCourseProgress(GroupInterface $course): bool {
    if (!$course instanceof Course) {
      return FALSE;
    }
    if ($course->isNew()) {
      return FALSE;
    }

    $query = $this->entityTypeManager->getStorage('lms_course_status')->getQuery();
    $in_progress_count = $query
      ->accessCheck(FALSE)
      ->condition(CourseStatusInterface::COURSE_FIELD, $course->id())
      ->condition('status', [
        CourseStatusInterface::STATUS_PROGRESS,
        CourseStatusInterface::STATUS_NEEDS_EVALUATION,
      ], 'IN')
      ->count()
      ->execute();

    return $in_progress_count !== 0;
  }

}
