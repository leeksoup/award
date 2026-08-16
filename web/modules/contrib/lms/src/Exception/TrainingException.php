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
      self::REQUIRED_NOT_STARTED => $this->t('Required lesson not started'),
      self::REQUIRED_NOT_FINISHED => $this->t('Required lesson not finished'),
      self::INSUFFICIENT_SCORE => $this->t('Required score on previous lesson not met'),
      self::NO_LESSONS => $this->t('This learning path has no lessons'),
      self::LESSON_REMOVED => $this->t('The requested lesson is not a part of the course'),
      self::ACTIVITY_REMOVED => $this->t('The requested activity is not a part of the lesson'),
      self::BACKWARDS_NAV_DISALLOWED => $this->t('This lesson does not allow backwards navigation.'),
      self::FREE_NAV_DISALLOWED => $this->t('This course does not allow free navigation.'),
      self::COURSE_NEEDS_EVALUATION => $this->t('This course awaits grading.'),
      self::INVALID_BACKWARDS_PARAMETERS => $this->t('Not possible to go backwards.'),
      self::COURSE_INITIALIZATION_ERROR => $this->t('Access to course denied.'),
      self::INCORRECT_ACTIVITY_REQUESTED => $this->t('Incorrect activity requested.'),
      self::CUSTOM_ACCESS_DENIED => $this->t('Access denied.'),
      self::COURSE_REMOVED => $this->t("The course doesn't exist anymore."),
    ];

    if ($customMessage !== NULL) {
      $message = $customMessage;
    }
    elseif (!\array_key_exists($code, $messages)) {
      throw new \InvalidArgumentException('Invalid error code provided.');
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
