<?php
// phpcs:ignoreFile

declare(strict_types=1);

/**
 * @file
 * Hooks provided by the Drupal LMS module. OOP style.
 */

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Url;
use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Exception\TrainingException;

final class Hooks {
  /**
   * Act on lesson initialization.
   *
   * Make changes to new unsaved lesson status entity and / or throw a new
   * training exception to deny lesson access and redirect.
   */
  #[Hook('lms_initialize_lesson')]
  public function initializeLesson(\Drupal\lms\Entity\LessonStatusInterface $lesson_status): void {
    throw new TrainingException(NULL, 13, NULL, t('No lesson access, you have been redirected'), Url::fromRoute('entity.node.canonical', ['node' => 1]));
  }

  /**
   * Alter the properties passed to the course_card component.
   *
   * This hook is called before the props are passed to the course_card SDC
   * component. It is the ideal place for modules to add, remove, or change
   * the data that the card will render.
   *
   * @param array &$props
   *   The array of properties for the component, passed by reference. This array
   *   includes keys such as 'title', 'url', 'description', and 'extra_fields'.
   *   To add a new custom field, append an array with 'label' and 'content' keys
   *   to the 'extra_fields' array. The 'content' should be a render array.
   * @param \Drupal\lms\Entity\Bundle\Course $course
   *   The Course entity object being rendered in the view row.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Cacheability to include additional dependencies if needed.
   *
   * @see \Drupal\lms\Plugin\views\row\CourseCardRow::buildCard()
   */
  #[Hook('views_row_lms_course_card_alter')]
  function viewsRowLmsCourseCardAlter(array &$props, Course $course, CacheableMetadata $cacheability): void {
    // Example: Add a simple static field.
    $props['extra_fields'][] = [
      'label' => t('Credits'),
      'content' => ['#markup' => '3'],
    ];
  }

  /**
   * Alter the details of an answer before it is displayed.
   *
   * This hook is called when viewing the details of a specific answer, for
   * example in the evaluation modal. It allows modules to add, remove, or modify
   * the render array before it is returned.
   *
   * @param array &$build
   *   The render array for the answer details page, passed by reference.
   * @param \Drupal\lms\Entity\AnswerInterface $answer
   *   The answer entity being displayed.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability metadata for the render array. Any added content should
   *   be accompanied by its corresponding cacheable metadata.
   *
   * @see \Drupal\lms\Controller\CourseController::answerDetails()
   */
  #[Hook('lms_answer_details_alter')]
  function lmsAnswerDetailsAlter(array &$build, AnswerInterface $answer, CacheableMetadata $cacheability): void {
    // Example: Add a custom message to the top of the answer detail view.
    $build['custom_message'] = [
      '#markup' => t('This answer is under review.'),
      '#weight' => -100,
    ];
  }

}
