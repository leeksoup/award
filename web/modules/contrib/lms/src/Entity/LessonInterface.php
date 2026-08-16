<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\lms\Entity\Handlers\LmsLessonHandlerInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for defining Lesson entities.
 */
interface LessonInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  public const ACTIVITIES = 'activities';

  /**
   * Gets the Lesson creation timestamp.
   *
   * @return int
   *   Creation timestamp of the Lesson.
   */
  public function getCreatedTime(): ?int;

  /**
   * Sets the Lesson creation timestamp.
   *
   * @param int $timestamp
   *   The Lesson creation timestamp.
   *
   * @return \Drupal\lms\Entity\LessonInterface
   *   The called Lesson entity.
   */
  public function setCreatedTime(int $timestamp): LessonInterface;

  /**
   * Returns the Lesson entity randomization setting.
   *
   * @return int
   *   The Lesson entity randomization setting.
   */
  public function getRandomization(): int;

  /**
   * Returns the Lesson entities' backwards navigation setting.
   *
   * @return bool
   *   The entities' backwards navigation setting.
   */
  public function getBackwardsNavigation(): bool;

  /**
   * Returns the Lesson entities' random activities count.
   *
   * @return int
   *   The Lesson entities' random activities count.
   */
  public function getRandomActivitiesCount(): int;

  /**
   * Sets the Lesson entities' random activity count.
   *
   * @param int $activityCount
   *   The amount of random activities.
   */
  public function setRandomActivitiesCount(int $activityCount): self;

  /**
   * Self-explanatory.
   */
  public function getLessonHandlerService(): LmsLessonHandlerInterface;

}
