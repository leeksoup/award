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
      $this->messenger()->addWarning($this->t('Last activity completed, training still has unfinished activities.'));
    }
    elseif ($course_status->getStatus() === CourseStatusInterface::STATUS_NEEDS_EVALUATION) {
      $this->messenger()->addStatus($this->t('Course completed, please wait for evaluation.'));
    }
    elseif ($course_status->getStatus() === CourseStatusInterface::STATUS_PASSED) {
      $this->messenger()->addStatus($this->t('Course completed.'));
    }
    elseif ($course_status->getStatus() === CourseStatusInterface::STATUS_FAILED) {
      $this->messenger()->addError($this->t('Course failed.'));
    }

    // @todo Optional redirection to results?
    return Url::fromRoute('entity.group.canonical', [
      'group' => $course_status->getCourseId(),
    ]);
  }

}
