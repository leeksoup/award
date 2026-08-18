<?php

declare(strict_types=1);

namespace Drupal\lms_forum_prompt\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Exception\TrainingException;
use Drupal\lms\TrainingManager;
use Drupal\node\NodeInterface;

/**
 * Handles Forum Prompt topic creation and completion.
 */
final class ForumPromptManager {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TrainingManager $trainingManager,
  ) {}

  /**
   * Completes a Forum Prompt activity and returns its forum topic.
   */
  public function completeActivity(
    Course $course,
    int $lesson_delta,
    int $activity_delta,
    AccountInterface $account,
  ): NodeInterface {
    $lesson_status = $this->trainingManager->getRequestedLessonStatus($course, $account, [
      'lesson' => $lesson_delta,
      'activity' => $activity_delta,
    ]);

    $activity = $lesson_status->getActivity($activity_delta);
    if (!$activity instanceof ActivityInterface || $activity->bundle() !== 'forum_prompt') {
      throw new TrainingException($lesson_status->getCourseStatus(), TrainingException::INCORRECT_ACTIVITY_REQUESTED);
    }

    $topic = $this->ensureTopicForActivity($activity, $course);
    if (!$topic instanceof NodeInterface) {
      throw new \RuntimeException(\sprintf('Forum Prompt activity %s has no forum topic.', $activity->id()));
    }

    $answer = $this->trainingManager->loadAnswer($lesson_status, $activity);
    if ($answer === NULL) {
      $answer = $this->trainingManager->createAnswer($lesson_status, $activity);
    }

    $lesson = $lesson_status->getLesson();
    $max_score = $this->trainingManager->getActivityMaxScore($lesson, $activity);
    $answer
      ->setEvaluated(TRUE)
      ->setScore($max_score)
      ->save();

    $course_status = $lesson_status->getCourseStatus();
    $this->trainingManager->setLastActivityTime($course_status);

    $next_activity_delta = $lesson_status->getNextActivityDelta($activity);
    $next_lesson_status = NULL;
    if ($next_activity_delta === NULL) {
      $this->trainingManager->updateLessonStatus($lesson_status);
      try {
        $next_lesson_status = $this->trainingManager->getNextLessonStatus($course_status);
      }
      catch (\Exception $e) {
        $this->trainingManager->updateCourseStatus($course_status);
        throw $e;
      }

      if ($next_lesson_status !== NULL) {
        $next_lesson_status->setCurrentActivityDelta(0);
        $next_lesson_status->save();
        $course_status->set('current_lesson_status', $next_lesson_status);
      }

      $this->trainingManager->updateCourseStatus($course_status, $next_lesson_status === NULL);
    }
    else {
      $lesson_status->setCurrentActivityDelta($next_activity_delta);
      $lesson_status->save();
      $course_status->save();
    }

    return $topic;
  }

  /**
   * Ensures all Forum Prompt activities in a course have topics.
   */
  public function ensureTopicsForCourse(Course $course): void {
    if (!$course->hasField('field_default_forum') || $course->get('field_default_forum')->isEmpty()) {
      return;
    }

    foreach ($this->forumPromptActivities($course) as $activity) {
      $this->ensureTopicForActivity($activity, $course);
    }
  }

  /**
   * Ensures Forum Prompt activities in a lesson have topics when possible.
   */
  public function ensureTopicsForLesson(LessonInterface $lesson): void {
    $courses = $this->resolveCoursesForLesson($lesson);
    foreach ($courses as $course) {
      $this->ensureTopicsForCourse($course);
    }
  }

  /**
   * Creates a forum topic for an activity if it does not already have one.
   */
  public function ensureTopicForActivity(ActivityInterface $activity, ?Course $course = NULL): ?NodeInterface {
    if ($activity->bundle() !== 'forum_prompt') {
      return NULL;
    }

    if ($activity->hasField('field_forum_topic') && !$activity->get('field_forum_topic')->isEmpty()) {
      $topic = $activity->get('field_forum_topic')->entity;
      if ($topic instanceof NodeInterface) {
        return $topic;
      }
    }

    $course ??= $this->resolveCourseForActivity($activity);
    if (
      !$course instanceof Course
      || !$course->hasField('field_default_forum')
      || $course->get('field_default_forum')->isEmpty()
    ) {
      return NULL;
    }

    $body = [];
    if ($activity->hasField('field_forum_prompt_body') && !$activity->get('field_forum_prompt_body')->isEmpty()) {
      $body = $activity->get('field_forum_prompt_body')->first()->getValue();
    }

    $topic = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'forum',
      'title' => $activity->label(),
      'uid' => $activity->getOwnerId(),
      'status' => 1,
      'taxonomy_forums' => [
        'target_id' => $course->get('field_default_forum')->target_id,
      ],
      'body' => $body,
    ]);
    \assert($topic instanceof NodeInterface);
    $topic->save();

    $activity->set('field_forum_topic', $topic);
    $activity->save();

    return $topic;
  }

  /**
   * Checks whether a course references any Forum Prompt activities.
   */
  public function courseContainsForumPrompt(Course $course): bool {
    return \count($this->forumPromptActivities($course)) > 0;
  }

  /**
   * Returns Forum Prompt activities referenced by the course.
   *
   * @return \Drupal\lms\Entity\ActivityInterface[]
   *   Forum Prompt activities keyed by activity ID.
   */
  private function forumPromptActivities(Course $course): array {
    $activities = [];
    foreach ($course->get(Course::LESSONS) as $lesson_item) {
      $lesson = $lesson_item->entity;
      if (!$lesson instanceof LessonInterface) {
        continue;
      }
      foreach ($lesson->get(LessonInterface::ACTIVITIES) as $activity_item) {
        $activity = $activity_item->entity;
        if ($activity instanceof ActivityInterface && $activity->bundle() === 'forum_prompt') {
          $activities[$activity->id()] = $activity;
        }
      }
    }
    return $activities;
  }

  /**
   * Finds a course that references a lesson containing this activity.
   */
  private function resolveCourseForActivity(ActivityInterface $activity): ?Course {
    $lesson_storage = $this->entityTypeManager->getStorage('lms_lesson');
    $lesson_ids = $lesson_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('activities.target_id', $activity->id())
      ->execute();
    if ($lesson_ids === []) {
      return NULL;
    }

    $course_storage = $this->entityTypeManager->getStorage('group');
    $course_ids = $course_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'lms_course')
      ->condition('lessons.target_id', $lesson_ids, 'IN')
      ->range(0, 1)
      ->execute();
    if ($course_ids === []) {
      return NULL;
    }

    $course = $course_storage->load(\reset($course_ids));
    return $course instanceof Course ? $course : NULL;
  }

  /**
   * Finds courses that reference a lesson.
   *
   * @return \Drupal\lms\Entity\Bundle\Course[]
   *   Courses keyed by course ID.
   */
  private function resolveCoursesForLesson(LessonInterface $lesson): array {
    $course_storage = $this->entityTypeManager->getStorage('group');
    $course_ids = $course_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'lms_course')
      ->condition('lessons.target_id', $lesson->id())
      ->execute();
    if ($course_ids === []) {
      return [];
    }

    return \array_filter(
      $course_storage->loadMultiple($course_ids),
      static fn ($course): bool => $course instanceof Course,
    );
  }

}
