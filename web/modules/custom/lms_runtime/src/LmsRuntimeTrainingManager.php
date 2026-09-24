<?php

declare(strict_types=1);

namespace Drupal\lms_runtime;

use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms\Exception\TrainingException;
use Drupal\lms\TrainingManager;

/**
 * Fixes backwards navigation when LMS initializes a previous lesson on demand.
 */
final class LmsRuntimeTrainingManager extends TrainingManager {

  /**
   * {@inheritdoc}
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
    if ($deltas['current_lesson'] === 0 && $deltas['current_activity'] === 0) {
      return [];
    }

    $deltas['lesson'] = $deltas['current_activity'] === 0
      ? $deltas['current_lesson'] - 1
      : $deltas['current_lesson'];

    if ($deltas['current_activity'] === 0) {
      $lesson_item = $course_status->getCourse()->getLessonItem($deltas['lesson']);
      if ($lesson_item === NULL) {
        return [];
      }
      $previous_lesson_status = $this->loadLessonStatus(
        $course_status->id(),
        $lesson_item->target_id,
      );
      if ($previous_lesson_status === NULL) {
        try {
          $previous_lesson_status = $this->initializeLesson($course_status, $lesson_item);
        }
        catch (TrainingException) {
          return [];
        }
      }
      // Core LMS omitted this assignment when it initialized the status above.
      $deltas['activity'] = $previous_lesson_status->getActivityCount() - 1;
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

}
