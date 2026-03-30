<?php

namespace Drupal\achievements_learning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves course relationships and completion checks.
 */
class LearningCourseManager {

  /**
   * Constructs the course manager.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LearningSectionManager $sectionManager,
    protected ?object $trainingManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Determines whether a course is complete for a user.
   */
  public function isCourseComplete(int $uid, int $courseId): bool {
    if ($this->trainingManager && method_exists($this->trainingManager, 'getCurrentCourseStatus')) {
      $course_status_complete = $this->isCourseCompleteViaTrainingManager($uid, $courseId);
      if ($course_status_complete !== NULL) {
        return $course_status_complete;
      }
    }

    $item_ids = $this->getCourseLessonsAndQuizzes($courseId);
    if ($item_ids === []) {
      return FALSE;
    }

    $completed = achievements_storage_get('al:completed_items', $uid);
    $completed = is_array($completed) ? $completed : [];

    foreach ($item_ids as $item_id) {
      if (!in_array($item_id, $completed, TRUE)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Attempts course completion evaluation via LMS TrainingManager.
   *
   * @return bool|null
   *   TRUE/FALSE when determinable, NULL when unavailable/unsupported.
   */
  protected function isCourseCompleteViaTrainingManager(int $uid, int $courseId): ?bool {
    $course_entity_type = (string) ($this->configFactory->get('achievements_learning.settings')->get('course_entity_type') ?: 'node');
    $course = $this->entityTypeManager->getStorage($course_entity_type)->load($courseId);
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$course || !$account) {
      return NULL;
    }

    try {
      $course_status = $this->trainingManager->getCurrentCourseStatus($course, $account);
      if (!$course_status) {
        return NULL;
      }

      if (method_exists($course_status, 'isCompleted')) {
        return (bool) $course_status->isCompleted();
      }

      if (method_exists($course_status, 'hasField') && $course_status->hasField('completed')) {
        return (bool) $course_status->get('completed')->value;
      }

      if (method_exists($course_status, 'hasField') && $course_status->hasField('status')) {
        return in_array((string) $course_status->get('status')->value, ['completed', 'done', 'passed'], TRUE);
      }
    }
    catch (\Throwable $exception) {
      $this->logger->warning('TrainingManager completion lookup failed for uid @uid course @course: @message', [
        '@uid' => $uid,
        '@course' => $courseId,
        '@message' => $exception->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Finds the course for a lesson.
   */
  public function getLessonCourseId(int $lessonId): ?int {
    $section_ids = $this->sectionManager->getLessonSectionIds($lessonId);
    if ($section_ids === []) {
      return NULL;
    }

    $config = $this->configFactory->get('achievements_learning.settings');
    $course_entity_type = (string) ($config->get('course_entity_type') ?: 'node');
    $course_bundle = (string) ($config->get('course_bundle') ?: 'course');
    $course_section_field = (string) ($config->get('course_section_reference_field') ?: 'field_course_module');

    try {
      $ids = $this->entityTypeManager->getStorage($course_entity_type)->getQuery()
        ->condition('type', $course_bundle)
        ->condition($course_section_field, $section_ids, 'IN')
        ->range(0, 1)
        ->accessCheck(FALSE)
        ->execute();
      return $ids ? (int) reset($ids) : NULL;
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Failed to resolve course for lesson @lesson: @message', [
        '@lesson' => $lessonId,
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Collects all lesson/assessment IDs referenced by a course.
   *
   * @return int[]
   *   Item IDs that must be completed for the course to be complete.
   */
  protected function getCourseLessonsAndQuizzes(int $courseId): array {
    $config = $this->configFactory->get('achievements_learning.settings');
    $course_entity_type = (string) ($config->get('course_entity_type') ?: 'node');
    $course_bundle = (string) ($config->get('course_bundle') ?: 'course');
    $course_section_field = (string) ($config->get('course_section_reference_field') ?: 'field_course_module');

    $course = $this->entityTypeManager->getStorage($course_entity_type)->load($courseId);
    if (!$course || (method_exists($course, 'bundle') && $course->bundle() !== $course_bundle)) {
      return [];
    }

    if (!method_exists($course, 'hasField') || !$course->hasField($course_section_field)) {
      return [];
    }

    $item_ids = [];
    foreach ($course->get($course_section_field)->getValue() as $ref) {
      if (empty($ref['target_id'])) {
        continue;
      }
      $item_ids = array_merge($item_ids, $this->sectionManager->getSectionLessonsAndQuizzes((int) $ref['target_id']));
    }

    return array_values(array_unique(array_map('intval', $item_ids)));
  }

}
