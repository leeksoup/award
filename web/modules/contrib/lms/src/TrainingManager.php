<?php

declare(strict_types=1);

namespace Drupal\lms;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lms\Plugin\ActivityAnswerInterface;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\Exception\TrainingException;
use Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

/**
 * The Training manager.
 */
class TrainingManager {

  /**
   * The constructor.
   */
  public function __construct(
    #[AutowireCallable(service: EntityTypeManagerInterface::class, method: 'getStorage')]
    protected \Closure $getStorage,
    #[AutowireCallable(service: TimeInterface::class, method: 'getRequestTime')]
    private \Closure $getRequestTime,
    #[Autowire(service: 'logger.channel.training_manager', lazy: TRUE)]
    protected readonly LoggerChannelInterface $logger,
    #[Autowire(service: ActivityAnswerManager::class, lazy: TRUE)]
    protected readonly ActivityAnswerManager $activityAnswerManager,
    #[Autowire(service: ModuleHandlerInterface::class, lazy: TRUE)]
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Gets a learning path status entity given a learning path and user.
   *
   * @param \Drupal\lms\Entity\Bundle\Course $training
   *   The training.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param mixed[] $conditions
   *   Array of query conditions.
   *
   * @return \Drupal\lms\Entity\CourseStatusInterface|null
   *   Course Status entity or NULL.
   */
  public function loadCourseStatus(Course $training, AccountInterface $account, array $conditions = []): ?CourseStatusInterface {
    $course_status_storage = ($this->getStorage)('lms_course_status');
    $query = $course_status_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition(CourseStatusInterface::COURSE_FIELD, $training->id())
      ->condition('uid', $account->id());

    foreach ($conditions as $property => $value) {
      $query->condition($property, $value);
    }
    $query->sort('started', 'DESC');
    $query->range(0, 1);
    $result = $query->execute();

    if ($result) {
      return $course_status_storage->load(\reset($result));
    }
    return NULL;
  }

  /**
   * Code saver.
   */
  public function loadLessonStatus(string $course_status_id, string $lesson_id): ?LessonStatusInterface {
    $statuses = $this->loadLessonStatusMultiple($course_status_id, [$lesson_id]);
    if (\count($statuses) === 0) {
      return NULL;
    }
    return \reset($statuses);
  }

  /**
   * Code saver.
   *
   * @return \Drupal\lms\Entity\LessonStatusInterface[]
   *   User lesson status array.
   */
  public function loadLessonStatusMultiple(string $course_status_id, array $lesson_ids): array {
    $lesson_status_storage = ($this->getStorage)('lms_lesson_status');
    $query = $lesson_status_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('course_status', $course_status_id)
      ->condition(LessonStatusInterface::LESSON_FIELD, $lesson_ids, 'IN');
    $results = $query->execute();
    return $lesson_status_storage->loadMultiple($results);
  }

  /**
   * Helper method to get all lessons ordered.
   *
   * No branching support.
   *
   * @return array
   *   Steps data.
   */
  public function getOrderedLessons(Course $group): array {
    $lesson_items = $group->get(Course::LESSONS);
    $result = [];
    /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem $lesson_item */
    foreach ($lesson_items as $lesson_delta => $lesson_item) {
      /** @var \Drupal\lms\Entity\LessonInterface */
      $lesson = $lesson_item->entity;
      $randomization = $lesson->getRandomization();
      $result[$lesson_item->target_id] = [
        'label' => $lesson->label(),
        'delta' => $lesson_delta,
        'activities' => [],
      ];

      // If we don't have randomization, activities are not attempt-specific
      // and can be populated here as they are identical as in lesson statuses.
      if ($randomization === 0) {
        $activity_items = $lesson->get(LessonInterface::ACTIVITIES);
        /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem $activity_item */
        foreach ($activity_items as $activity_delta => $activity_item) {
          $result[$lesson_item->target_id]['activities'][$activity_item->target_id] = [
            'label' => $activity_item->entity->label(),
            'delta' => $activity_delta,
            'activity' => $activity_item->entity,
            'answered' => FALSE,
            'evaluated' => FALSE,
            'max_score_achieved' => FALSE,
            'max_score' => NULL,
            'score' => NULL,
          ];
        }
      }
    }
    return $result;
  }

  /**
   * Get lesson for course status and delta.
   */
  public function getLessonByDelta(CourseStatusInterface $course_status, int $lesson_delta): ?LessonInterface {
    $lesson = NULL;
    $course = $course_status->getCourse();
    $lesson_item = $course->getLessonItem($lesson_delta);
    if ($lesson_item !== NULL) {
      // If lesson status exists, get lesson from it to make sure we have
      // the right revision.
      $lesson_status = $this->loadLessonStatus($course_status->id(), $lesson_item->target_id);
      if ($lesson_status !== NULL) {
        $lesson = $lesson_status->getLesson();
      }
    }
    if ($lesson === NULL) {
      $lesson = $course->getLesson($lesson_delta);
    }
    return $lesson;
  }

  /**
   * Check access to a requested activity.
   *
   * @param \Drupal\lms\Entity\CourseStatusInterface $course_status
   *   Course status entity.
   * @param array<int> $deltas
   *   The requested and current lesson and activity deltas.
   *   Required keys:
   *     - lesson,
   *     - current_lesson,
   *     - activity,
   *     - current_activity.
   *
   * @return ?int
   *   TrainingException status flag or NULL.
   */
  public function checkActivityAccess(CourseStatusInterface $course_status, array $deltas): ?int {

    $course = $course_status->getCourse();

    // Allow any activity case.
    if ($course->hasFreeNavigation($course_status->getUser())) {
      return NULL;
    }

    // Revisiting case.
    if (
      $course_status->isFinished() &&
      $course->revisitMode()
    ) {
      return NULL;
    }

    // Forward navigation case.
    if (
      ($deltas['lesson'] > $deltas['current_lesson']) ||
      (
        $deltas['lesson'] === $deltas['current_lesson'] &&
        $deltas['activity'] > $deltas['current_activity']
      )
    ) {
      // Allowed if previous activity is already answered.
      // For activity-less lessons (e.g. instructor-led), check finished status.
      if ($deltas['activity'] === 0) {
        $lesson_item = $course->getLessonItem($deltas['lesson'] - 1);
        if ($lesson_item === NULL) {
          return TrainingException::INCORRECT_ACTIVITY_REQUESTED;
        }
        $lesson_status = $this->loadLessonStatus($course_status->id(), $lesson_item->target_id);
        if ($lesson_status === NULL) {
          return TrainingException::FREE_NAV_DISALLOWED;
        }
        if ($lesson_status->getActivityCount() === 0) {
          return $lesson_status->isFinished() ? NULL : TrainingException::FREE_NAV_DISALLOWED;
        }
        $activity = $lesson_status->getActivity($lesson_status->getActivityCount() - 1);
      }
      else {
        $lesson_item = $course->getLessonItem($deltas['lesson']);
        if ($lesson_item === NULL) {
          return TrainingException::INCORRECT_ACTIVITY_REQUESTED;
        }
        $lesson_status = $this->loadLessonStatus($course_status->id(), $lesson_item->target_id);
        if ($lesson_status === NULL) {
          return TrainingException::FREE_NAV_DISALLOWED;
        }
        $activity = $lesson_status->getActivity($deltas['activity'] - 1);
      }

      if ($activity === NULL) {
        return TrainingException::INCORRECT_ACTIVITY_REQUESTED;
      }

      $answer = $this->loadAnswer($lesson_status, $activity);
      if ($answer === NULL) {
        return TrainingException::FREE_NAV_DISALLOWED;
      }

      return NULL;
    }
    // Backwards navigation case.
    elseif (
      ($deltas['lesson'] < $deltas['current_lesson']) ||
      (
        $deltas['lesson'] === $deltas['current_lesson'] &&
        $deltas['activity'] < $deltas['current_activity']
      )
    ) {
      // Disallow if the current lesson or any previous lessons until the
      // target one don't allow backwards navigation.
      for ($delta = $deltas['current_lesson']; $delta >= $deltas['lesson']; $delta--) {
        $lesson = $this->getLessonByDelta($course_status, $delta);
        if ($lesson === NULL) {
          return TrainingException::INCORRECT_ACTIVITY_REQUESTED;
        }
        if (!$lesson->getBackwardsNavigation()) {
          return TrainingException::BACKWARDS_NAV_DISALLOWED;
        }
      }

      return NULL;
    }

    // Default: disallow navigation.
    // NOTE: there may be a case when student skipped some activities
    // / lessons in free nav mode and teacher turned off free nav mode
    // afterwards. In such a case going back to the skipped activities
    // will be impossible if backwards nav is disabled on lessons.
    return TrainingException::FREE_NAV_DISALLOWED;
  }

  /**
   * Get requested lesson and activity deltas for a course.
   */
  public function getRequestedLessonStatus(
    Course $course,
    AccountInterface $account,
    ?array $requested_deltas = [],
  ): LessonStatusInterface {
    $course_status = $this->getCurrentCourseStatus($course, $account);

    // Don't allow to manually visit courses that have "needs grading" status.
    if (
      $course_status->getStatus() === CourseStatusInterface::STATUS_NEEDS_EVALUATION &&
      !$course->ungradedAccess()
    ) {
      throw new TrainingException(NULL, TrainingException::COURSE_NEEDS_EVALUATION);
    }

    // Current lesson status must be set and exist at this point.
    $lesson_status = $course_status->getCurrentLessonStatus();
    if ($lesson_status === NULL) {
      throw new TrainingException(NULL, TrainingException::COURSE_INITIALIZATION_ERROR);
    }

    $lesson_delta = $lesson_status->getCurrentLessonDelta();
    $activity_delta = $lesson_status->getCurrentActivityDelta();

    if (!\array_key_exists('lesson', $requested_deltas)) {
      $requested_deltas['lesson'] = $lesson_delta;
      $same_lesson = TRUE;
    }
    elseif ($requested_deltas['lesson'] === $lesson_delta) {
      $same_lesson = TRUE;
    }
    else {
      $same_lesson = FALSE;
    }
    if (!\array_key_exists('activity', $requested_deltas)) {
      $requested_deltas['activity'] = $activity_delta;
      $same_activity = TRUE;
    }
    elseif ($requested_deltas['activity'] === $activity_delta) {
      $same_activity = TRUE;
    }
    else {
      $same_activity = FALSE;
    }

    $lessons_field = $course_status->getCourse()->get(Course::LESSONS);

    // Validate deltas if the expected next activity is requested.
    if ($same_lesson && $same_activity) {
      /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem */
      $lesson_item = $lessons_field->get($lesson_delta);
      $lesson_status = $this->loadLessonStatus($course_status->id(), $lesson_item->target_id);
      if ($lesson_status === NULL) {
        $lesson_status = $this->initializeLesson($course_status, $lesson_item);
      }
      $this->validateCourseDeltas(
        $course_status,
        $lesson_status,
        [
          'lesson' => $lesson_delta,
          'activity' => $activity_delta,
        ]
      );
      return $lesson_status;
    }

    if (!$lessons_field->offsetExists($requested_deltas['lesson'])) {
      throw new TrainingException($course_status, TrainingException::LESSON_REMOVED);
    }

    $deltas = $requested_deltas;
    $deltas['current_activity'] = $activity_delta;
    $deltas['current_lesson'] = $lesson_delta;

    $reason = $this->checkActivityAccess($course_status, $deltas);
    if ($reason !== NULL) {
      throw new TrainingException($course_status, $reason);
    }

    // Different lesson - initialize the lesson status if not previously
    // taken with the requested activity and update Course Status information.
    $lesson_item = $lessons_field->get($requested_deltas['lesson']);
    \assert($lesson_item instanceof LMSReferenceItem);
    $lesson_status = $this->loadLessonStatus($course_status->id(), $lesson_item->target_id);
    if ($lesson_status === NULL) {
      $lesson_status = $this->initializeLesson($course_status, $lesson_item);
    }

    if (!$same_lesson) {
      $course_status->set('current_lesson_status', $lesson_status);
      $course_status->save();
    }

    $lesson_status->setCurrentActivityDelta($requested_deltas['activity']);
    $lesson_status->save();

    return $lesson_status;
  }

  /**
   * Initialize a lesson.
   */
  public function initializeLesson(CourseStatusInterface $course_status, LMSReferenceItem $lesson_item): LessonStatusInterface {
    $previous_lesson_delta = $this->getPreviousLessonDelta($course_status, $lesson_item->target_id);

    if ($previous_lesson_delta !== NULL) {
      $previous_lesson = $this->getLessonByDelta($course_status, $previous_lesson_delta);
      // Check if The previous lesson requirements are met.
      \assert($previous_lesson instanceof LessonInterface);
      $previous_lesson_item = $course_status->getCourse()->getLessonItem($previous_lesson_delta);
      $previous_lesson_status = $this->loadLessonStatus($course_status->id(), $previous_lesson_item->target_id);

      try {
        $previous_lesson
          ->getLessonHandlerService()
          ->checkRequirements($course_status, $previous_lesson_status, $previous_lesson_item);
      }
      catch (TrainingException $e) {
        // Requirements not met, go back where possible.
        if ($previous_lesson_status === NULL) {
          $previous_lesson_status = $this->initializeLesson($course_status, $previous_lesson_item);
        }
        $previous_lesson_status->setCurrentActivityDelta(0);
        $previous_lesson_status->save();
        $course_status->set('current_lesson_status', $previous_lesson_status);
        $course_status->save();

        // Throw the exception again so it can be caught for further actions
        // (redirecting and displaying a message).
        throw $e;
      }
    }

    \assert($lesson_item->entity instanceof LessonInterface);
    $lesson_status = $lesson_item->entity
      ->getLessonHandlerService()
      ->initializeLesson($course_status, $lesson_item);

    // Allow other modules to act on lesson initialization, modify the lesson
    // status or throw the TrainingException to deny access / redirect.
    $this->moduleHandler->invokeAll('lms_initialize_lesson', [$lesson_status]);

    return $lesson_status;
  }

  /**
   * Validate lesson and activity delta on a course.
   */
  protected function validateCourseDeltas(CourseStatusInterface $course_status, LessonStatusInterface $lesson_status, array $deltas): void {
    $lessons_field = $course_status->getCourse()->get(Course::LESSONS);
    if (!$lessons_field->offsetExists($deltas['lesson'])) {
      throw new TrainingException(NULL, TrainingException::LESSON_REMOVED);
    }
    /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem */
    $item = $lessons_field->get($deltas['lesson']);
    if (!$item->entity instanceof LessonInterface) {
      throw new TrainingException(NULL, TrainingException::LESSON_REMOVED);
    }

    $activities_field = $lesson_status->get(LessonInterface::ACTIVITIES);
    if ($activities_field->isEmpty()) {
      return;
    }
    if (!$activities_field->offsetExists($deltas['activity'])) {
      throw new TrainingException(NULL, TrainingException::ACTIVITY_REMOVED);
    }

    /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem */
    $item = $activities_field->get($deltas['activity']);
    if (!$item->entity instanceof ActivityInterface) {
      throw new TrainingException(NULL, TrainingException::ACTIVITY_REMOVED);
    }
  }

  /**
   * Helper method to get next lesson for Course Status.
   *
   * @param \Drupal\lms\Entity\CourseStatusInterface $course_status
   *   The course status.
   * @param bool $initialize
   *   Should the status be initialized if it doesn't exist?
   */
  public function getNextLessonStatus(CourseStatusInterface $course_status, bool $initialize = TRUE): ?LessonStatusInterface {
    $lesson_status = $course_status->getCurrentLessonStatus();
    if ($lesson_status === NULL) {
      return NULL;
    }

    $current_lesson_id = $lesson_status->getLessonId();
    $course = $course_status->getCourse();
    $lesson_items = $course->get(Course::LESSONS);
    /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem $lesson_item */
    foreach ($lesson_items as $delta => $lesson_item) {
      if ($current_lesson_id === $lesson_item->get('target_id')->getValue()) {
        $next_delta = $delta + 1;
        if (!$lesson_items->offsetExists($next_delta)) {
          return NULL;
        }
        /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem */
        $next_lesson_item = $lesson_items->get($next_delta);
        $lesson_status = $this->loadLessonStatus($course_status->id(), $next_lesson_item->target_id);
        if ($lesson_status !== NULL) {
          return $lesson_status;
        }
        if ($initialize) {
          return $this->initializeLesson($course_status, $next_lesson_item);
        }
      }
    }

    return NULL;
  }

  /**
   * Get previous lesson reference item.
   */
  private function getPreviousLessonDelta(CourseStatusInterface $course_status, string $lesson_id): ?int {
    $lesson_items = $course_status->getCourse()->get(Course::LESSONS);
    /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem $lesson_item */
    foreach ($lesson_items as $delta => $lesson_item) {
      if ($delta === 0) {
        continue;
      }
      if ($lesson_item->target_id === $lesson_id) {
        return $delta - 1;
      }
    }

    return NULL;
  }

  /**
   * Gets backwards navigation parameters for Course Status.
   *
   * @return int[]
   *   Keyed array with 2 integer values:
   *     - lesson_delta,
   *     - activity_delta
   *   or empty array if there is nowhere to go back or disallowed.
   */
  public function getBackNavDeltas(AnswerInterface $answer): array {
    $lesson_status = $answer->getLessonStatus();
    $course_status = $lesson_status->getCourseStatus();

    $current_activity_delta = $lesson_status->getCurrentActivityDelta();
    if ($current_activity_delta === NULL) {
      return [];
    }

    $deltas = [
      'current_lesson' => $lesson_status->getCurrentLessonDelta(),
      'current_activity' => $current_activity_delta,
    ];

    // Nowhere to go back to.
    if (
      $deltas['current_lesson'] === 0 &&
      $deltas['current_activity'] === 0
    ) {
      return [];
    }

    if ($deltas['current_activity'] === 0) {
      $deltas['lesson'] = $deltas['current_lesson'] - 1;
    }
    else {
      $deltas['lesson'] = $deltas['current_lesson'];
    }

    if ($deltas['current_activity'] === 0) {
      $lesson_item = $course_status->getCourse()->getLessonItem($deltas['lesson']);
      if ($lesson_item === NULL) {
        return [];
      }
      $previous_lesson_status = $this->loadLessonStatus($course_status->id(), $lesson_item->target_id);
      if ($previous_lesson_status === NULL) {
        try {
          $previous_lesson_status = $this->initializeLesson($course_status, $lesson_item);
        }
        catch (TrainingException $e) {
          // Backwards navigation is not possible
          // if the previous lesson couldn't be initialized.
          return [];
        }
      }
      else {
        $deltas['activity'] = $previous_lesson_status->getActivityCount() - 1;
      }
    }
    else {
      $deltas['activity'] = $deltas['current_activity'] - 1;
    }

    $reason = $this->checkActivityAccess($course_status, $deltas);
    return $reason !== NULL ? [] : [
      'lesson' => $deltas['lesson'],
      'activity' => $deltas['activity'],
    ];
  }

  /**
   * Perform all operations required to start a training.
   *
   * @return \Drupal\lms\Entity\CourseStatusInterface
   *   User status for the given course.
   */
  public function initializeTraining(Course $group, AccountInterface $user): CourseStatusInterface {
    $current_lesson_item = $group->get(Course::LESSONS)->first();
    if (!$current_lesson_item instanceof LMSReferenceItem) {
      throw new TrainingException(NULL, TrainingException::NO_LESSONS);
    }

    // Create a Course Status entity.
    $request_time = ($this->getRequestTime)();
    $course_status = ($this->getStorage)('lms_course_status')->create([
      CourseStatusInterface::COURSE_FIELD => $group->id(),
      'uid' => $user->id(),
      'last_activity_ts' => $request_time,
      'finished' => 0,
      'status' => CourseStatusInterface::STATUS_PROGRESS,
      'started' => $request_time,
    ]);

    $lesson_status = $this->initializeLesson($course_status, $current_lesson_item);
    $course_status->save();
    $lesson_status->save();
    $course_status->set('current_lesson_status', $lesson_status);
    $course_status->save();

    return $course_status;
  }

  /**
   * Get learning path status.
   */
  public function getCurrentCourseStatus(Course $group, AccountInterface $user): CourseStatusInterface {
    $course_status = $this->loadCourseStatus($group, $user, $group->getInitialCourseStatusConditions());

    if ($course_status === NULL) {
      return $this->initializeTraining($group, $user);
    }
    return $course_status;
  }

  /**
   * Load answer entity for a specified lesson status and activity.
   */
  public function loadAnswer(LessonStatusInterface $lesson_status, ActivityInterface $activity): ?AnswerInterface {
    $answer_storage = ($this->getStorage)('lms_answer');
    $query = $answer_storage->getQuery();
    $result = $query
      ->accessCheck(FALSE)
      ->condition('lesson_status', $lesson_status->id())
      ->condition(AnswerInterface::ACTIVITY_FIELD, $activity->id())
      ->execute();
    return \count($result) === 0 ? NULL : $answer_storage->load(\reset($result));
  }

  /**
   * Get answer entity for a specified lesson status and activity.
   */
  public function createAnswer(LessonStatusInterface $lesson_status, ActivityInterface $activity): AnswerInterface {
    return ($this->getStorage)('lms_answer')->create([
      'user_id' => $lesson_status->getCourseStatus()->getUserId(),
      AnswerInterface::ACTIVITY_FIELD => $activity->id(),
      'lesson_status' => $lesson_status->id(),
      'score' => 0,
    ]);
  }

  /**
   * Get max score for an activity.
   */
  public function getActivityMaxScore(LessonInterface $lesson, ActivityInterface $activity): int {
    /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem */
    foreach ($lesson->get(LessonInterface::ACTIVITIES) as $item) {
      if ($item->target_id === $activity->id()) {
        return $item->getMaxScore();
      }
    }
    return 0;
  }

  /**
   * Get answer plugin ID for a given activity.
   */
  public function getActivityAnswerPlugin(ActivityInterface $activity): ?ActivityAnswerInterface {
    $activity_type_id = $activity->bundle();
    /** @var \Drupal\lms\Entity\ActivityTypeInterface|NULL */
    $activity_type = ($this->getStorage)('lms_activity_type')->load($activity_type_id);
    if ($activity_type === NULL) {
      return NULL;
    }
    $plugin_id = $activity_type->getPluginId();
    if (!$this->activityAnswerManager->hasDefinition($plugin_id)) {
      return NULL;
    }

    return $this->activityAnswerManager->createInstance($plugin_id, $activity_type->getPluginConfiguration());
  }

  /**
   * Helper method to get answers by activity ID from a lesson status.
   *
   * @param \Drupal\lms\Entity\LessonStatusInterface $lesson_status
   *   The obvious.
   * @param array<int> $activity_ids
   *   Validation purpose - array of activity IDs that should belong to
   *   the current lesson.
   */
  public function getAnswersByActivityId(LessonStatusInterface $lesson_status, ?array $activity_ids = NULL): array {
    $answers = ($this->getStorage)('lms_answer')->loadByProperties([
      'lesson_status' => $lesson_status->id(),
    ]);
    $answers_by_activity_id = [];
    foreach ($answers as $answer) {
      $activity_id = $answer->get(AnswerInterface::ACTIVITY_FIELD)->target_id;
      // Dangling references in field items case.
      if ($activity_ids !== NULL && !\in_array((int) $activity_id, $activity_ids, TRUE)) {
        $this->logger->warning('Answer (ID: %answer_id) to a activity (ID: %activity_id) that does not exist in lesson (ID: %lesson_id) found.', [
          '%answer_id' => $answer->id(),
          '%activity_id' => $activity_id,
          '%lesson_id' => $lesson_status->getLessonId(),
        ]);
        continue;
      }
      $answers_by_activity_id[$activity_id] = $answer;
    }
    return $answers_by_activity_id;
  }

  /**
   * Check if all answers for a lesson status are evaluated.
   */
  public function allAnswersEvaluated(LessonStatusInterface $lesson_status): bool {
    $query = ($this->getStorage)('lms_answer')->getQuery();
    $count = $query
      ->accessCheck(FALSE)
      ->condition('lesson_status', $lesson_status->id())
      ->condition('evaluated', FALSE)
      ->count()
      ->execute();
    return $count === 0;
  }

  /**
   * Check if all lesson statuses for a course status are evaluated.
   */
  public function allLessonStatusesEvaluated(CourseStatusInterface $course_status): bool {
    $query = ($this->getStorage)('lms_lesson_status')->getQuery();
    $count = $query
      ->accessCheck(FALSE)
      ->condition('course_status', $course_status->id())
      ->condition('evaluated', FALSE)
      ->count()
      ->execute();
    return $count === 0;
  }

  /**
   * Update user lesson status data.
   *
   * @return bool
   *   Did the user lesson status change?
   */
  public function updateLessonStatus(LessonStatusInterface $lesson_status): bool {
    return $lesson_status->getLesson()
      ->getLessonHandlerService()
      ->updateStatus($lesson_status);
  }

  /**
   * Set last activity timestamp on Course status entity.
   */
  public function setLastActivityTime(CourseStatusInterface $course_status): void {
    $course_status->setLastActivity(($this->getRequestTime)());
  }

  /**
   * Calculate Course status data.
   *
   * @param \Drupal\lms\Entity\CourseStatusInterface $course_status
   *   The Course Status entity to update.
   * @param bool $last_activity
   *   Did the user just completed the last activity?
   */
  public function updateCourseStatus(CourseStatusInterface $course_status, bool $last_activity = FALSE): void {
    $course = $course_status->getCourse();
    $lesson_ids = [];

    $lesson_items = $course->get(Course::LESSONS);
    /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem $lesson_item */
    foreach ($lesson_items as $lesson_item) {
      $lesson_ids[$lesson_item->target_id] = $lesson_item->target_id;
    }

    /** @var \Drupal\lms\Entity\LessonStatusInterface[] */
    $lesson_statuses = ($this->getStorage)('lms_lesson_status')->loadByProperties([
      'course_status' => $course_status->id(),
    ]);
    $lesson_statuses_by_lesson_id = [];
    foreach ($lesson_statuses as $status) {
      $lesson_id = $status->getLessonId();
      $lesson_statuses_by_lesson_id[$lesson_id] = $status;
    }

    $all_finished = TRUE;
    $all_mandatory_answered = TRUE;
    $all_mandatory_evaluated = TRUE;
    $all_mandatory_completed = TRUE;
    $max_score = 0;
    $score_weighted_sum = 0;
    /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem $lesson_item */
    foreach ($lesson_items as $lesson_item) {
      $lesson_id = $lesson_item->target_id;

      // Lesson not started.
      if (!\array_key_exists($lesson_id, $lesson_statuses_by_lesson_id)) {
        $all_finished = FALSE;
        if ($lesson_item->isMandatory()) {
          $all_mandatory_answered = FALSE;
          $all_mandatory_evaluated = FALSE;
          $all_mandatory_completed = FALSE;
        }
        continue;
      }

      $lesson_status = $lesson_statuses_by_lesson_id[$lesson_id];

      // Lesson not finished.
      if (!$lesson_status->isFinished()) {
        $all_finished = FALSE;
        if ($lesson_status->isMandatory()) {
          $all_mandatory_answered = FALSE;
          $all_mandatory_evaluated = FALSE;
          $all_mandatory_completed = FALSE;
        }
        continue;
      }

      $lesson_score = $lesson_status->getScore();
      $lesson_attempt_max_score = $lesson_status->getMaxScore();
      $max_score += $lesson_attempt_max_score;
      $score_weighted_sum += $lesson_score * $lesson_attempt_max_score;
      if ($lesson_status->isMandatory()) {
        if (!$lesson_status->isEvaluated()) {
          $all_mandatory_evaluated = FALSE;
          $all_mandatory_completed = FALSE;
        }
        elseif ($lesson_score < $lesson_status->getRequiredScore()) {
          $all_mandatory_completed = FALSE;
        }
      }
    }

    if (!$course_status->isFinished()) {
      if (
        $all_finished ||
        // Set Course as finished if the user just answered the last activity
        // and all mandatory activities have been finished (branching or
        // free navigation case).
        ($last_activity && $all_mandatory_answered)
      ) {
        $course_status->setFinished(($this->getRequestTime)());
      }
    }

    // Determine the status of the Course.
    if ($course_status->isFinished()) {
      if ($all_mandatory_completed) {
        $status = CourseStatusInterface::STATUS_PASSED;
      }
      elseif (!$all_mandatory_evaluated) {
        $status = CourseStatusInterface::STATUS_NEEDS_EVALUATION;
      }
      else {
        $status = CourseStatusInterface::STATUS_FAILED;
      }
    }
    else {
      $status = CourseStatusInterface::STATUS_PROGRESS;
    }

    // Revisiting a training - don't update status if previously passed.
    $revisiting_passed = (
      $course_status->getStatus() === CourseStatusInterface::STATUS_PASSED &&
      $course_status->getCourse()->revisitMode()
    );
    if (!$revisiting_passed) {
      $course_status->setStatus($status);
    }

    // Set score if course is finished and evaluated.
    if ($course_status->isFinished() && $all_mandatory_evaluated) {
      if ($max_score === 0) {
        $course_status->setScore(100);
      }
      else {
        $course_status->setScore((int) \round($score_weighted_sum / $max_score));
      }
    }
    else {
      $course_status->setScore(NULL);
    }

    $course_status->save();
    // @todo Add percent complete field to Course Status and calculate basing
    // on total questions and answered questions count here to optimize some
    // views.
  }

  /**
   * Defines the training status cache tag.
   */
  public static function trainingStatusTag(string $group_id, string $user_id): string {
    return 'training_status:' . $group_id . ':' . $user_id;
  }

  /**
   * Calculate user course data.
   *
   * Utility method for updating corrupt data (should be used in an post
   * update hook, hence the ID parameters).
   *
   * @return bool
   *   Was the recalculation successful?
   */
  public function updateUserCourseData(string $group_id, string $user_id): bool {

    $group = ($this->getStorage)('group')->load($group_id);
    if ($group === NULL || !$group instanceof Course) {
      return FALSE;
    }
    $user = ($this->getStorage)('user')->load($user_id);
    if ($user === NULL) {
      return FALSE;
    }

    // Recalculate all status entities for this user and group.
    $course_statuses = ($this->getStorage)('lms_course_status')->loadByProperties([
      'uid' => $user->id(),
      CourseStatusInterface::COURSE_FIELD => $group->id(),
    ]);
    if (\count($course_statuses) === 0) {
      return FALSE;
    }

    foreach ($course_statuses as $course_status) {
      $lesson_statuses = ($this->getStorage)('lms_lesson_status')->loadByProperties([
        'course_status' => $course_status->id(),
      ]);

      // Update lesson statuses.
      $status_data = [];
      foreach ($lesson_statuses as $lesson_status) {
        $this->updateLessonStatus($lesson_status);
        $status_data[$lesson_status->id()] = [
          'percent' => $lesson_status->get('percent')->value,
          'started' => $lesson_status->getCreatedTime(),
        ];
      }

      // There should be only one per Course Status so delete all but the
      // best or the latest one.
      if (\count($status_data) > 1) {
        $preserve_id = '';
        $max_percentage = 0;
        foreach ($status_data as $id => $item) {
          if ($item['percent'] > $max_percentage) {
            $preserve_id = $id;
          }
        }
        if ($preserve_id === '') {
          $latest_ts = 0;
          foreach ($status_data as $id => $item) {
            if ($item['started'] > $latest_ts) {
              $preserve_id = $id;
            }
          }
        }

        // If $preserve_id is still set to an empty string, looks like
        // we can delete all anyway.
        foreach ($lesson_statuses as $id => $lesson_status) {
          if ($id === $preserve_id) {
            continue;
          }
          $lesson_status->delete();
        }
      }

      // Recalculate Course status.
      $this->updateCourseStatus($course_status);
    }

    return TRUE;
  }

  /**
   * Reset training.
   *
   * Development and data fixing purpose.
   *
   * @todo Use for the case of modifications to courses that are already
   * in progress by some students.
   */
  public function resetTraining(string $group_id, ?string $user_id = NULL): void {
    $course_status_storage = ($this->getStorage)('lms_course_status');

    $course_status_query = $course_status_storage->getQuery();
    $course_status_query
      ->accessCheck(FALSE)
      ->condition(CourseStatusInterface::COURSE_FIELD, $group_id);
    if ($user_id !== NULL) {
      $course_status_query->condition('uid', $user_id);
    }

    $course_status_ids = $course_status_query->execute();

    // If user ID is not provided and some course statuses are
    // completed - only clear current lesson and current activity values
    // if students wanted to revisit the training.
    if ($user_id === NULL) {
      foreach ($course_status_storage->loadMultiple($course_status_ids) as $course_status) {
        if (
          $course_status->getStatus() === CourseStatusInterface::STATUS_PROGRESS ||
          $course_status->getStatus() === CourseStatusInterface::STATUS_NEEDS_EVALUATION
        ) {
          continue;
        }
        $course_status->set('current_lesson_status', NULL);
        $course_status->save();
        unset($course_status_ids[$course_status->id()]);
      }
    }

    // Lesson statuses and answers will be handled in entity delete hooks.
    if (\count($course_status_ids) !== 0) {
      foreach ($course_status_storage->loadMultiple($course_status_ids) as $course_status) {
        $course_status->delete();
      }
    }
  }

}
