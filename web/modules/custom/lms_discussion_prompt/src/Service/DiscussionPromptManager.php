<?php

declare(strict_types=1);

namespace Drupal\lms_discussion_prompt\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Exception\TrainingException;
use Drupal\lms\TrainingManager;
use Drupal\lms_discussion_prompt\Value\DiscussionPromptCompletion;
use Drupal\node\NodeInterface;

/**
 * Handles Discussion Prompt node creation and completion.
 */
final class DiscussionPromptManager {

  private const RELATION_PLUGIN = 'group_node:discussion';

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TrainingManager $trainingManager,
  ) {}

  /**
   * Completes a Discussion Prompt activity and determines where to resume.
   */
  public function completeActivity(
    Course $course,
    int $lesson_delta,
    int $activity_delta,
    AccountInterface $account,
  ): DiscussionPromptCompletion {
    $lesson_status = $this->trainingManager->getRequestedLessonStatus($course, $account, [
      'lesson' => $lesson_delta,
      'activity' => $activity_delta,
    ]);

    $activity = $lesson_status->getActivity($activity_delta);
    if (!$activity instanceof ActivityInterface || $activity->bundle() !== 'discussion_prompt') {
      throw new TrainingException(
        $lesson_status->getCourseStatus(),
        TrainingException::INCORRECT_ACTIVITY_REQUESTED,
      );
    }

    $discussion = $this->ensureDiscussionForActivity($activity, $course);
    if (!$discussion instanceof NodeInterface) {
      throw new \RuntimeException(\sprintf(
        'Discussion Prompt activity %s has no discussion.',
        $activity->id(),
      ));
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
        $next_activity_delta = 0;
        $next_lesson_status->setCurrentActivityDelta($next_activity_delta);
        $next_lesson_status->save();
        $course_status->set('current_lesson_status', $next_lesson_status);
        $lesson_status = $next_lesson_status;
      }

      $this->trainingManager->updateCourseStatus($course_status, $next_lesson_status === NULL);
    }
    else {
      $lesson_status->setCurrentActivityDelta($next_activity_delta);
      $lesson_status->save();
      $course_status->save();
    }

    if ($next_activity_delta === NULL) {
      $return_url = Url::fromRoute('entity.group.canonical', [
        'group' => $course_status->getCourseId(),
      ]);
    }
    else {
      $return_url = Url::fromRoute('lms.group.answer_form', [
        'group' => $course_status->getCourseId(),
        'lesson_delta' => $lesson_status->getCurrentLessonDelta(),
        'activity_delta' => $lesson_status->getCurrentActivityDelta(),
      ]);
    }

    return new DiscussionPromptCompletion($discussion, $return_url);
  }

  /**
   * Ensures all Discussion Prompt activities in a course have discussions.
   */
  public function ensureDiscussionsForCourse(Course $course): void {
    foreach ($this->discussionPromptActivities($course) as $activity) {
      $this->ensureDiscussionForActivity($activity, $course);
    }
  }

  /**
   * Ensures Discussion Prompt activities in a lesson have discussions.
   */
  public function ensureDiscussionsForLesson(LessonInterface $lesson): void {
    foreach ($this->resolveCoursesForLesson($lesson) as $course) {
      $this->ensureDiscussionsForCourse($course);
    }
  }

  /**
   * Gets the course return URL for a linked discussion.
   */
  public function getReturnUrlForDiscussion(NodeInterface $discussion): ?Url {
    if ($discussion->bundle() !== 'discussion') {
      return NULL;
    }

    foreach ($this->activitiesForDiscussion($discussion) as $activity) {
      $course = $this->resolveCourseForActivity($activity);
      if ($course instanceof Course) {
        return Url::fromRoute('lms.course.start', [
          'group' => $course->id(),
        ]);
      }
    }

    return NULL;
  }

  /**
   * Creates a discussion for an activity if it does not already have one.
   */
  public function ensureDiscussionForActivity(
    ActivityInterface $activity,
    ?Course $course = NULL,
  ): ?NodeInterface {
    if ($activity->bundle() !== 'discussion_prompt') {
      return NULL;
    }

    if (
      $activity->hasField('field_discussion_node')
      && !$activity->get('field_discussion_node')->isEmpty()
    ) {
      $discussion = $activity->get('field_discussion_node')->entity;
      if ($discussion instanceof NodeInterface) {
        $course ??= $this->resolveCourseForActivity($activity);
        if ($course instanceof Course) {
          $this->ensureDiscussionAttached($discussion, $course);
        }
        return $discussion;
      }
    }

    $course ??= $this->resolveCourseForActivity($activity);
    if (!$course instanceof Course || !$this->courseSupportsDiscussions($course)) {
      return NULL;
    }

    $body = [];
    if (
      $activity->hasField('field_discussion_prompt_body')
      && !$activity->get('field_discussion_prompt_body')->isEmpty()
    ) {
      $body = $activity->get('field_discussion_prompt_body')->first()->getValue();
    }

    $title = $this->discussionTitle($activity);
    // Only an explicit discussion title signals that an existing course
    // discussion may be reused. Falling back to a generic activity label such
    // as "Discussion" must not cause unrelated prompts to share one node.
    if (
      $activity->hasField('field_discussion_title')
      && !$activity->get('field_discussion_title')->isEmpty()
    ) {
      $existing_discussion = $this->loadDiscussionByTitle($title, $course);
      if ($existing_discussion instanceof NodeInterface) {
        $this->ensureDiscussionAttached($existing_discussion, $course);
        $activity->set('field_discussion_node', $existing_discussion);
        $activity->save();
        return $existing_discussion;
      }
    }

    $discussion = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'discussion',
      'title' => $title,
      'uid' => $activity->getOwnerId(),
      'status' => 1,
      'body' => $body,
      'comment_discussion' => [
        'status' => 2,
      ],
    ]);
    \assert($discussion instanceof NodeInterface);
    $discussion->save();

    $course->addRelationship($discussion, self::RELATION_PLUGIN);
    $activity->set('field_discussion_node', $discussion);
    $activity->save();

    return $discussion;
  }

  /**
   * Ensures a discussion node is attached to a course group.
   */
  private function ensureDiscussionAttached(NodeInterface $discussion, Course $course): void {
    if (
      $discussion->bundle() !== 'discussion'
      || !$this->courseSupportsDiscussions($course)
      || $course->getRelationshipsByEntity($discussion, self::RELATION_PLUGIN) !== []
    ) {
      return;
    }

    $course->addRelationship($discussion, self::RELATION_PLUGIN);
  }

  /**
   * Checks whether a course references any Discussion Prompt activities.
   */
  public function courseContainsDiscussionPrompt(Course $course): bool {
    return \count($this->discussionPromptActivities($course)) > 0;
  }

  /**
   * Returns the requested discussion title for an activity.
   */
  private function discussionTitle(ActivityInterface $activity): string {
    if (
      $activity->hasField('field_discussion_title')
      && !$activity->get('field_discussion_title')->isEmpty()
    ) {
      return (string) $activity->get('field_discussion_title')->value;
    }

    return $activity->label();
  }

  /**
   * Loads an existing course discussion with an exact title match.
   */
  private function loadDiscussionByTitle(string $title, Course $course): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'discussion')
      ->condition('title', $title)
      ->execute();
    if ($ids === []) {
      return NULL;
    }

    $relationships = $course->getRelationships(self::RELATION_PLUGIN);
    $course_discussion_ids = [];
    foreach ($relationships as $relationship) {
      $course_discussion_ids[] = (string) $relationship->getEntityId();
    }

    foreach ($ids as $id) {
      if (in_array((string) $id, $course_discussion_ids, TRUE)) {
        $discussion = $storage->load($id);
        return $discussion instanceof NodeInterface ? $discussion : NULL;
      }
    }

    return NULL;
  }

  /**
   * Finds Discussion Prompt activities linked to a discussion.
   *
   * @return \Drupal\lms\Entity\ActivityInterface[]
   *   Discussion Prompt activities.
   */
  private function activitiesForDiscussion(NodeInterface $discussion): array {
    $storage = $this->entityTypeManager->getStorage('lms_activity');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'discussion_prompt')
      ->condition('field_discussion_node.target_id', $discussion->id())
      ->execute();

    if ($ids === []) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'discussion_prompt')
        ->condition('field_discussion_title', $discussion->label())
        ->execute();
    }

    return \array_filter(
      $storage->loadMultiple($ids),
      static fn ($activity): bool => $activity instanceof ActivityInterface,
    );
  }

  /**
   * Returns Discussion Prompt activities referenced by the course.
   *
   * @return \Drupal\lms\Entity\ActivityInterface[]
   *   Discussion Prompt activities keyed by activity ID.
   */
  private function discussionPromptActivities(Course $course): array {
    $activities = [];
    foreach ($course->get(Course::LESSONS) as $lesson_item) {
      $lesson = $lesson_item->entity;
      if (!$lesson instanceof LessonInterface) {
        continue;
      }
      foreach ($lesson->get(LessonInterface::ACTIVITIES) as $activity_item) {
        $activity = $activity_item->entity;
        if ($activity instanceof ActivityInterface && $activity->bundle() === 'discussion_prompt') {
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

  /**
   * Checks whether the Course group type can contain discussion nodes.
   */
  private function courseSupportsDiscussions(Course $course): bool {
    $relationship_type = $this->entityTypeManager
      ->getStorage('group_relationship_type')
      ->load($course->bundle() . '-group_node-discussion');

    return $relationship_type !== NULL;
  }

}
