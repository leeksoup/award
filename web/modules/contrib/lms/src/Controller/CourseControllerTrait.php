<?php

declare(strict_types=1);

namespace Drupal\lms\Controller;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\Exception\TrainingException;

/**
 * Common methods for training navigation logic classes.
 */
trait CourseControllerTrait {

  /**
   * Error handler.
   */
  private function handleError(Course $group, \Exception $e): Url {
    // Training exception - reset current lesson and activity and redirect
    // to the lesson.
    if ($e instanceof TrainingException) {
      if (($url = $e->getRedirectUrl()) !== NULL) {
        $this->messenger()->addWarning($e->getMessage());
        return $url;
      }
      $iterations = 0;
      // Loop could be used in case of free navigation, otherwise there'll
      // always be a single iteration without additional exceptions.
      do {
        $success = TRUE;
        $course_status = $e->getCourseStatus();
        if ($course_status !== NULL) {
          $this->messenger()->addWarning($e->getMessage());
          try {
            $lesson_status = $this->trainingManager->getRequestedLessonStatus($group, $this->currentUser());
            return Url::fromRoute('lms.group.answer_form', [
              'group' => $group->id(),
              'lesson_delta' => $lesson_status->getCurrentLessonDelta(),
              'activity_delta' => $lesson_status->getCurrentActivityDelta(),
            ]);
          }
          catch (TrainingException $nested_exception) {
            $success = FALSE;
            $e = $nested_exception;
          }
        }
        else {
          if ($e->getCode() === TrainingException::NO_LESSONS) {
            $this->messenger()->addError($this->t('This course has no lessons.'));
          }
          else {
            $this->messenger()->addError($e->getMessage());
          }
          return Url::fromRoute('entity.group.canonical', [
            'group' => $group->id(),
          ]);
        }

        $iterations++;
        if ($iterations > 50) {
          throw new \Exception('Too many iterations, please inspect the code.');
        }
        // First lesson always can be initialized so no risk of infinitely
        // throwing a TrainingException.
        // @phpstan-ignore booleanNot.alwaysTrue
      } while (!$success);
    }

    // Other exception type - redirect to the group, log exception and
    // display a generic message.
    $this->messenger()->addError($this->t('An error occurred while trying to start training. Please report the issue to your site administrator.'));
    Error::logException($this->getLogger('lms'), $e, 'Training @group_id start error for user @user_id. Message: @message Backtrace: @backtrace_string', [
      '@group_id' => $group->id(),
      '@user_id' => $this->currentUser()->id(),
      '@message' => $e->getMessage(),
    ]);
    return Url::fromRoute('entity.group.canonical', [
      'group' => $group->id(),
    ]);
  }

  /**
   * Lesson status summary getter.
   */
  public function getLessonStatusSummary(LessonStatusInterface $lesson_status): TranslatableMarkup {
    if ($lesson_status->isNew()) {
      return $this->t('@lesson (not started)', [
        '@lesson' => $lesson_status->getLesson()->label(),
      ]);
    }
    if ($lesson_status->getActivityCount() === 0) {
      $result = $lesson_status->isEvaluated()
        ? $this->t('score: @score%', ['@score' => $lesson_status->getScore()])
        : $this->t('not evaluated');
      return $this->t('@lesson (@result)', [
        '@lesson' => $lesson_status->getLesson()->label(),
        '@result' => $result,
      ]);
    }

    return $this->t('@lesson (completed @completed / @total activities, @result)', [
      '@lesson' => $lesson_status->getLesson()->label(),
      '@completed' => $lesson_status->getAnswerCount(),
      '@total' => $lesson_status->getActivityCount(),
      '@result' => $lesson_status->isEvaluated() ? $this->t('score: @score% / @required% required', [
        '@score' => $lesson_status->getScore(),
        '@required' => $lesson_status->getRequiredScore(),
      ]) : $this->t('not all evaluated'),
    ]);
  }

}
