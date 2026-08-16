<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Provides an interface for defining Lesson Status entities.
 */
interface LessonStatusInterface extends ContentEntityInterface, EntityChangedInterface {

  public const LESSON_FIELD = 'lesson_revision';
  public const ACTIVITIES_FIELD = 'activity_revisions';

  /**
   * Is this lesson status over time?
   */
  public function isOverTime(): bool;

  /**
   * Get lesson status close time, zero if there's no time limit.
   */
  public function getCloseTime(): int;

  /**
   * Get course status for this lesson status.
   */
  public function getCourseStatus(): CourseStatusInterface;

  /**
   * Set finished timestamp.
   */
  public function setFinished(int $timestamp = 0): self;

  /**
   * Is this status evaluated?
   */
  public function isEvaluated(): bool;

  /**
   * Set evaluated value.
   */
  public function setEvaluated(bool $value): self;

  /**
   * Is this lesson finished?
   */
  public function isFinished(): bool;

  /**
   * Set score for this lesson.
   */
  public function setScore(int $value): self;

  /**
   * Get score for this lesson.
   */
  public function getScore(): int;

  /**
   * Get maximum score for this lesson.
   */
  public function getMaxScore(): int;

  /**
   * Get score required to pass this lesson.
   */
  public function getRequiredScore(): int;

  /**
   * Set maximum score for this lesson.
   */
  public function setMaxScore(int $max_score): self;

  /**
   * Set pass percentage for the referenced lesson (question count based).
   */
  public function setPassPercentage(int $percentage): self;

  /**
   * Activity IDs getter.
   *
   * @return int[]
   *   Activity IDs array.
   */
  public function getActivityIds(): array;

  /**
   * Get activity entities.
   *
   * @return \Drupal\lms\Entity\ActivityInterface[]
   *   Array of activity entities keyed by field delta.
   */
  public function getActivities(): array;

  /**
   * Activities setter.
   *
   * @param string[] $activity_ids
   *   Activity IDs array.
   */
  public function setActivityIds(array $activity_ids): self;

  /**
   * Is this lesson mandatory?
   */
  public function isMandatory(): bool;

  /**
   * As in the method name.
   */
  public function getCurrentActivityDelta(): ?int;

  /**
   * Get next activity delta.
   *
   * @param \Drupal\lms\Entity\ActivityInterface|null $activity
   *   If given as an argument, gets next delta relative to the given activity.
   *   Otherwise gets next delta relative to the current one.
   */
  public function getNextActivityDelta(?ActivityInterface $activity = NULL): ?int;

  /**
   * As in the method name.
   */
  public function getLastActivityDelta(): ?int;

  /**
   * As in the method name.
   */
  public function getCurrentActivity(): ?ActivityInterface;

  /**
   * Get activity given its delta.
   */
  public function getActivity(int $delta): ?ActivityInterface;

  /**
   * As in the method name.
   */
  public function setCurrentActivityDelta(int $delta): self;

  /**
   * As in the method name.
   */
  public function getCurrentLessonDelta(): int;

  /**
   * As in the method name.
   */
  public function getNextLessonDelta(): ?int;

  /**
   * Get the lesson ID referenced by this lesson status.
   */
  public function getLessonId(): string;

  /**
   * Get the lesson referenced by this lesson status.
   */
  public function getLesson(): LessonInterface;

  /**
   * Self-explanatory.
   */
  public function getAnswerCount(): int;

  /**
   * Self-explanatory.
   */
  public function getActivityCount(): int;

  /**
   * Shortcut method to get referenced lesson answer form.
   */
  public function getAnswerForm(): array;

  /**
   * Shortcut method to get results renderable of this status.
   */
  public function buildResults(array &$element): void;

}
