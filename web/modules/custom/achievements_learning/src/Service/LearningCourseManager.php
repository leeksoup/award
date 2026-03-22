<?php

namespace Drupal\achievements_learning\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves course relationships and completion checks.
 */
class LearningCourseManager {

  /**
   * Constructs the course manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Determines whether a course is complete for a user.
   */
  public function isCourseComplete(int $uid, int $courseId): bool {
    $this->logger->debug('Course completion placeholder evaluated for uid @uid and course @course.', ['@uid' => $uid, '@course' => $courseId]);
    return FALSE;
  }

  /**
   * Finds the course for a lesson.
   */
  public function getLessonCourseId(int $lessonId): ?int {
    return NULL;
  }

}
