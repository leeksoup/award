<?php

declare(strict_types=1);

namespace Drupal\lms\Form;

use Drupal\Core\Url;
use Drupal\lms\Entity\CourseStatusInterface;

/**
 * Methods to be used in different answer forms.
 */
trait AnswerFormTrait {

  /**
   * Handle finished course.
   */
  private function handleFinishedCourse(CourseStatusInterface $course_status): Url {
    if ($course_status->getStatus() === CourseStatusInterface::STATUS_PROGRESS) {
      $this->messenger()->addWarning($this->t('You have reached the end of the course, but some earlier activities are incomplete. Complete them, then return here to finish the course.'));
    }
    elseif ($course_status->getStatus() === CourseStatusInterface::STATUS_NEEDS_EVALUATION) {
      $this->messenger()->addStatus($this->t('Course completed! Your answers are awaiting teacher evaluation.'));
    }
    elseif ($course_status->getStatus() === CourseStatusInterface::STATUS_PASSED) {
      $this->messenger()->addStatus($this->t('Course completed! You achieved a passing score.'));
    }
    elseif ($course_status->getStatus() === CourseStatusInterface::STATUS_FAILED) {
      $this->messenger()->addError($this->t('Course completed. Your score did not meet the passing requirement, so the course is marked as failed.'));
    }

    // @todo Optional redirection to results?
    return Url::fromRoute('entity.group.canonical', [
      'group' => $course_status->getCourseId(),
    ]);
  }

}
