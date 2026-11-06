<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Bundle;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem;

/**
 * Interface for Course group bundle.
 */
interface CourseInterface extends GroupInterface {

  public const LESSONS = 'lessons';

  /**
   * Check if the given user can take this learning path.
   */
  public function takeAccess(AccountInterface $account): AccessResult;

  /**
   * Check if group has guided navigation enabled.
   */
  public function hasFreeNavigation(?AccountInterface $account = NULL): bool;

  /**
   * Get loading conditions when starting / continuing a training.
   *
   * If no course status meeting the given conditions is found, training will be
   * (re) initialized.
   *
   * @return mixed[]
   *   Conditions for loading status entity when starting a course.
   */
  public function getInitialCourseStatusConditions(): array;

  /**
   * Check whether the course has revisit mode enabled.
   */
  public function revisitMode(): bool;

  /**
   * Check if a course that needs grading can be entered.
   */
  public function ungradedAccess(): bool;

  /**
   * Get lesson.
   */
  public function getLesson(int $lesson_delta): ?LessonInterface;

  /**
   * Get Lesson field item.
   */
  public function getLessonItem(int $lesson_delta): ?LMSReferenceItem;

}
