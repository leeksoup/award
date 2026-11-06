<?php

declare(strict_types=1);

namespace Drupal\lms\Exception;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lms\Entity\CourseStatusInterface;

/**
 * Defines a training exception class.
 */
final class TrainingException extends \Exception {

  use StringTranslationTrait;

  public const REQUIRED_NOT_STARTED = 1;
  public const REQUIRED_NOT_FINISHED = 2;
  public const INSUFFICIENT_SCORE = 3;
  public const NO_LESSONS = 4;
  public const LESSON_REMOVED = 5;
  public const ACTIVITY_REMOVED = 6;
  public const BACKWARDS_NAV_DISALLOWED = 7;
  public const FREE_NAV_DISALLOWED = 8;
  public const COURSE_NEEDS_EVALUATION = 9;
  public const INVALID_BACKWARDS_PARAMETERS = 10;
  public const COURSE_INITIALIZATION_ERROR = 11;
  public const INCORRECT_ACTIVITY_REQUESTED = 12;
  public const CUSTOM_ACCESS_DENIED = 13;
  public const COURSE_REMOVED = 14;

  /**
   * The obvious.
   */
  public function __construct(
    private readonly ?CourseStatusInterface $courseStatus = NULL,
    int $code = 0,
    ?\Throwable $previous = NULL,
    ?TranslatableMarkup $customMessage = NULL,
    private readonly ?Url $redirectUrl = NULL,
  ) {
    $messages = [
      self::REQUIRED_NOT_STARTED => $this->t('A required earlier lesson must be started before you can access this one.'),
      self::REQUIRED_NOT_FINISHED => $this->t('A required earlier lesson must be finished before you can access this one.'),
      self::INSUFFICIENT_SCORE => $this->t('A higher score on the previous lesson is required to unlock this content.'),
      self::NO_LESSONS => $this->t('This course does not have any lessons yet. A course creator must add at least one.'),
      self::LESSON_REMOVED => $this->t('This lesson has been removed from the course.'),
      self::ACTIVITY_REMOVED => $this->t('This activity has been removed from the lesson.'),
      self::BACKWARDS_NAV_DISALLOWED => $this->t('This lesson does not allow returning to previous activities.'),
      self::FREE_NAV_DISALLOWED => $this->t('This course requires activities to be completed in order. Submit each activity to continue to the next one.'),
      self::COURSE_NEEDS_EVALUATION => $this->t('This course is complete and awaiting grading. Check back later for your results.'),
      self::INVALID_BACKWARDS_PARAMETERS => $this->t('This course does not allow going back from this point.'),
      self::COURSE_INITIALIZATION_ERROR => $this->t('This course could not be started. Your account may not have access to it.'),
      self::INCORRECT_ACTIVITY_REQUESTED => $this->t('That activity cannot be accessed from here.'),
      self::CUSTOM_ACCESS_DENIED => $this->t('Your account does not have access to this course.'),
      self::COURSE_REMOVED => $this->t('This course has been removed and is no longer available.'),
    ];

    if ($customMessage !== NULL) {
      $message = $customMessage;
    }
    elseif (!\array_key_exists($code, $messages)) {
      throw new \InvalidArgumentException('There was an unknown error, with an invalid error code provided. Please report the cause of this error upstream.');
    }
    else {
      $message = $messages[$code];
    }
    parent::__construct((string) $message, $code, $previous);
  }

  /**
   * Course Status getter.
   */
  public function getCourseStatus(): ?CourseStatusInterface {
    return $this->courseStatus;
  }

  /**
   * Get custom redirect URL if provided.
   */
  public function getRedirectUrl(): ?Url {
    return $this->redirectUrl;
  }

}
