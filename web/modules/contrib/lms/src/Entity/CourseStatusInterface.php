<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Entity\Bundle\Course;

/**
 * Provides a CourseStatus interface.
 */
interface CourseStatusInterface extends ContentEntityInterface {

  public const STATUS_PROGRESS = 'in progress';
  public const STATUS_PASSED = 'passed';
  public const STATUS_FAILED = 'failed';
  public const STATUS_NEEDS_EVALUATION = 'evaluation';
  public const COURSE_FIELD = 'course';

  /**
   * Gets the related course ID of the course status entity.
   *
   * @return string
   *   The related course ID.
   */
  public function getCourseId(): string;

  /**
   * Gets the related course entity of the course status entity.
   *
   * @return \Drupal\lms\Entity\Bundle\Course|null
   *   The related course entity.
   */
  public function getCourse(): ?Course;

  /**
   * Gets the related user ID of the course status entity.
   *
   * @return string
   *   The related user ID.
   */
  public function getUserId(): string;

  /**
   * Gets the related user entity of the course status entity.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The related user entity.
   */
  public function getUser(): AccountInterface;

  /**
   * Gets the status of the course status entity.
   *
   * @return string
   *   The status.
   */
  public function getStatus(): string;

  /**
   * Set the status.
   */
  public function setStatus(string $status): void;

  /**
   * Gets the score of the course status entity.
   *
   * @return int
   *   The score.
   */
  public function getScore(): ?int;

  /**
   * Sets the score of the course status entity.
   */
  public function setScore(?int $value): void;

  /**
   * Get course finished timestamp, zero if not finished.
   */
  public function getFinished(): int;

  /**
   * Sets the finish timestamp of the course status entity.
   */
  public function setFinished(int $timestamp): self;

  /**
   * Checks if the course is finished.
   */
  public function isFinished(): bool;

  /**
   * Gets the student last activity timestamp in the course.
   */
  public function getLastActivity(): int;

  /**
   * Sets the student last activity timestamp for the course.
   */
  public function setLastActivity(int $timestamp): self;

  /**
   * Check if arbitrary activity is allowed on the course.
   */
  public function allowAnyActivity(): bool;

  /**
   * Get current lesson status.
   */
  public function getCurrentLessonStatus(): ?LessonStatusInterface;

  /**
   * Get status and score translated string.
   */
  public function getStatusAndScore(): string;

  /**
   * Get available status options.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Status options array keyed by status string.
   */
  public static function getStatusOptions(): array;

  /**
   * Central status name method.
   */
  public static function getStatusText(string $status): TranslatableMarkup;

}
