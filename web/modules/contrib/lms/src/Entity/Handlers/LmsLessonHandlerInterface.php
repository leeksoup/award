<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Form\FormStateInterface;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem;

/**
 * Interface for LMS lesson handler classes.
 */
interface LmsLessonHandlerInterface {

  /**
   * Initialize lesson.
   *
   * Creates a new lesson status linked to a CourseStatus.
   */
  public function initializeLesson(CourseStatusInterface $course_status, LMSReferenceItem $lesson_item): LessonStatusInterface;

  /**
   * Check if referenced lesson can be taken in the given context.
   *
   * @throws \Drupal\lms\Exception\TrainingException
   *   If the referenced lesson cannot be taken, the method must throw the
   *   TrainingException, specifying the reason.
   */
  public function checkRequirements(
    CourseStatusInterface $course_status,
    ?LessonStatusInterface $previous_lesson_status,
    LMSReferenceItem $previous_lesson_item,
  ): void;

  /**
   * Update user lesson status data.
   *
   * @return bool
   *   Did the user lesson status change?
   */
  public function updateStatus(LessonStatusInterface $lesson_status): bool;

  /**
   * Set results renderable array for the given lesson status.
   */
  public function buildResults(array &$element, LessonStatusInterface $lesson_status): void;

  /**
   * Answer form getter method.
   *
   * @return mixed[]
   *   Renderable array or redirect response.
   */
  public function getAnswerForm(LessonStatusInterface $lesson_status): array;

  /**
   * Alter lesson entity form.
   */
  public function alterLessonForm(array &$form, FormStateInterface $form_state): void;

}
