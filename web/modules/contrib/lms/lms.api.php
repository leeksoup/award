<?php

/**
 * @file
 * Hooks provided by the Drupal LMS module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Act on lesson initialization.
 *
 * Make changes to new unsaved lesson status entity and / or throw a new
 * training exception to deny lesson access and redirect.
 */
function hook_lms_initialize_lesson(\Drupal\lms\Entity\LessonStatusInterface $lesson_status): void {
  throw new \Drupal\lms\Exception\TrainingException(NULL, 13, NULL, t('No lesson access, you have been redirected'), \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => 1]));
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
 *
 * @example
 * // In MODULE.module, add a "Course Duration" field to the card.
 * function MODULE_views_row_lms_course_card_alter(array &$props,
 *   \Drupal\lms\Entity\Bundle\Course $course) {
 *   if ($course->hasField('field_course_duration') &&
 *     !$course->get('field_course_duration')->isEmpty()) {
 *     $props['extra_fields'][] = [
 *       'label' => t('Duration'),
 *       'content' => $course->get(
 *         'field_course_duration')->view(['label' => 'hidden']
 *        ),
 *     ];
 *   }
 * }
 */
function hook_views_row_lms_course_card_alter(array &$props, \Drupal\lms\Entity\Bundle\Course $course, \Drupal\Core\Cache\CacheableMetadata $cacheability) {
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
function hook_lms_answer_details_alter(array &$build, \Drupal\lms\Entity\AnswerInterface $answer, \Drupal\Core\Cache\CacheableMetadata $cacheability) {
  // Example: Add a custom message to the top of the answer detail view.
  $build['custom_message'] = [
    '#markup' => t('This answer is under review.'),
    '#weight' => -100,
  ];
}

/**
 * @} End of "addtogroup hooks".
 */

/**
 * @defgroup lms_training_manager_api Training Manager API
 * @{
 * Functions to interact with course and user progress data.
 *
 * The `lms.training_manager` service provides a high-level API for managing
 * student progress through courses, lessons, and activities.
 */

/**
 * Get a user's current status for a specific course.
 *
 * @see \Drupal\lms\TrainingManager::getCurrentCourseStatus()
 */
function lms_get_current_course_status(\Drupal\lms\Entity\Bundle\Course $course, \Drupal\Core\Session\AccountInterface $user) {}

/**
 * Get the specific lesson status for a user, based on requested deltas.
 *
 * @see \Drupal\lms\TrainingManager::getRequestedLessonStatus()
 */
function lms_get_requested_lesson_status(\Drupal\lms\Entity\Bundle\Course $course, \Drupal\Core\Session\AccountInterface $user, array $requested_deltas) {}

/**
 * Reset a user's progress for a course, or all progress if user is omitted.
 *
 * @see \Drupal\lms\TrainingManager::resetTraining()
 */
function lms_reset_training(string $group_id, ?string $user_id = NULL) {}

/**
 * Create a new answer entity for a user and activity.
 *
 * @see \Drupal\lms\TrainingManager::createAnswer()
 */
function lms_create_answer(\Drupal\lms\Entity\LessonStatusInterface $lesson_status, \Drupal\lms\Entity\ActivityInterface $activity) {}

/**
 * Load an existing answer entity.
 *
 * @see \Drupal\lms\TrainingManager::loadAnswer()
 */
function lms_load_answer(\Drupal\lms\Entity\LessonStatusInterface $lesson_status, \Drupal\lms\Entity\ActivityInterface $activity) {}

/**
 * Check if all answers in a lesson have been evaluated.
 *
 * @see \Drupal\lms\TrainingManager::allAnswersEvaluated()
 */
function lms_all_answers_evaluated(\Drupal\lms\Entity\LessonStatusInterface $lesson_status) {}

/**
 * Recalculate and save a lesson's status based on its answers.
 *
 * @see \Drupal\lms\TrainingManager::updateLessonStatus()
 */
function lms_update_lesson_status(\Drupal\lms\Entity\LessonStatusInterface $lesson_status) {}

/**
 * Recalculate and save a course's status based on its lessons.
 *
 * @see \Drupal\lms\TrainingManager::updateCourseStatus()
 */
function lms_update_course_status(\Drupal\lms\Entity\CourseStatusInterface $course_status, bool $last_activity = FALSE) {}

/**
 * Update the last activity timestamp for a user in a course.
 *
 * @see \Drupal\lms\TrainingManager::setLastActivityTime()
 */
function lms_set_last_activity_time(\Drupal\lms\Entity\CourseStatusInterface $course_status) {}

/**
 * Get an ordered list of lessons for a course.
 *
 * @see \Drupal\lms\TrainingManager::getOrderedLessons()
 */
function lms_get_ordered_lessons(\Drupal\lms\Entity\Bundle\Course $group) {}

/**
 * Initialize a lesson for a user, checking prerequisites.
 *
 * @see \Drupal\lms\TrainingManager::initializeLesson()
 */
function lms_initialize_lesson(\Drupal\lms\Entity\CourseStatusInterface $course_status, \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem $lesson_item) {}

/**
 * @} End of "defgroup lms_training_manager_api".
 */
