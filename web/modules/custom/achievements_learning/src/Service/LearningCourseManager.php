<?php

namespace Drupal\achievements_learning\Service;

use Drupal\anu_lms\CourseProgress;
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
    protected Lesson $lessonService,
    protected CourseProgress $courseProgress,
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

    $progress = $this->courseProgress->getCourseProgress($course);
    if ($progress === []) {
      return FALSE;
    }

    foreach ($progress as $item) {
      if (empty($item['completed'])) {
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
