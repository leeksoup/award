<?php

namespace Drupal\achievements_learning\Service;

use Drupal\anu_lms\Course;
use Drupal\anu_lms\Lesson;
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
    protected Course $courseService,
    protected Lesson $lessonService,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Determines whether a course is complete for a user.
   */
  public function isCourseComplete(int $uid, int $courseId): bool {
    /** @var \Drupal\node\NodeInterface|null $course */
    $course = $this->entityTypeManager->getStorage('node')->load($courseId);
    if (!$course || $course->bundle() !== 'course') {
      return FALSE;
    }

    $item_ids = $this->courseService->getLessonsAndQuizzes($course);
    if ($item_ids === []) {
      return FALSE;
    }

    foreach ($item_ids as $item_id) {
      if (!$this->lessonService->isCompletedByUser((int) $item_id, $uid)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Finds the course for a lesson.
   */
  public function getLessonCourseId(int $lessonId): ?int {
    $course = $this->lessonService->getLessonCourse($lessonId);
    return $course ? (int) $course->id() : NULL;
  }

}
