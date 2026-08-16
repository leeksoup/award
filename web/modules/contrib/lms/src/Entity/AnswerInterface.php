<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for answer entities.
 */
interface AnswerInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  public const ACTIVITY_FIELD = 'activity_revision';

  /**
   * Returns the activity entity related to this Answer entity.
   *
   * @return \Drupal\lms\Entity\ActivityInterface
   *   The activity entity related to the Answer entity.
   */
  public function getActivity(): ActivityInterface;

  /**
   * Returns the Lesson Status entity related to the Answer entity.
   *
   * @return \Drupal\lms\Entity\LessonStatusInterface
   *   The Lesson Status entity.
   */
  public function getLessonStatus(): LessonStatusInterface;

  /**
   * Determines if the Answer entity is evaluated.
   *
   * @return bool
   *   TRUE if the Answer entity is evaluated, FALSE otherwise.
   */
  public function isEvaluated(): bool;

  /**
   * Sets the evaluated status of the Answer entity.
   *
   * @param bool $evaluated
   *   The evaluation status.
   */
  public function setEvaluated(bool $evaluated): self;

  /**
   * Sets the score for the Answer entity.
   */
  public function setScore(int|float $score): self;

  /**
   * Get achieved score for this answer.
   */
  public function getScore(): int;

  /**
   * Set this answer's data.
   */
  public function setData(array $data): self;

  /**
   * Returns the data of the Answer entity.
   *
   * @return array
   *   The Answer entity's data.
   */
  public function getData(): array;

  /**
   * Get user ID of the student that gave the answer.
   */
  public function getUserId(): string;

}
