<?php

namespace Drupal\achievements_learning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves lesson section relationships and completion checks.
 */
class LearningSectionManager {

  /**
   * Constructs the section manager.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected Lesson $lessonService,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Determines whether a section is complete for a user.
   */
  public function isSectionComplete(int $uid, int $sectionId): bool {
    $item_ids = $this->getSectionLessonsAndQuizzes($sectionId);
    if ($item_ids === []) {
      return FALSE;
    }

    foreach ($item_ids as $item_id) {
      if (!$this->isItemCompletedByUser($uid, $item_id)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Returns section IDs for a lesson.
   */
  public function getLessonSectionIds(int $lessonId): array {
    $config = $this->configFactory->get('achievements_learning.settings');
    $section_entity_type = (string) ($config->get('section_entity_type') ?: 'paragraph');
    $section_bundle = (string) ($config->get('section_bundle') ?: 'course_modules');
    $lesson_ref_field = (string) ($config->get('section_lesson_reference_field') ?: 'field_module_lessons');
    $assessment_ref_field = (string) ($config->get('section_assessment_reference_field') ?: 'field_module_assessment');

    try {
      $query = $this->entityTypeManager->getStorage($section_entity_type)->getQuery()
        ->condition('type', $section_bundle)
        ->accessCheck(FALSE);

      $group = $query->orConditionGroup()->condition($lesson_ref_field, $lessonId);
      $group->condition($assessment_ref_field, $lessonId);
      $query->condition($group);

      return array_map('intval', array_values($query->execute()));
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Failed to resolve sections for lesson @lesson: @message', [
        '@lesson' => $lessonId,
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Returns lesson and quiz IDs associated with a section entity.
   */
  public function getSectionLessonsAndQuizzes(int $sectionId): array {
    $config = $this->configFactory->get('achievements_learning.settings');
    $section_entity_type = (string) ($config->get('section_entity_type') ?: 'paragraph');
    $section_bundle = (string) ($config->get('section_bundle') ?: 'course_modules');
    $lesson_ref_field = (string) ($config->get('section_lesson_reference_field') ?: 'field_module_lessons');
    $assessment_ref_field = (string) ($config->get('section_assessment_reference_field') ?: 'field_module_assessment');

    $section = $this->entityTypeManager->getStorage($section_entity_type)->load($sectionId);
    if (!$section || (method_exists($section, 'bundle') && $section->bundle() !== $section_bundle)) {
      return [];
    }

    $item_ids = [];
    foreach ([$lesson_ref_field, $assessment_ref_field] as $field_name) {
      if (!method_exists($section, 'hasField') || !$section->hasField($field_name)) {
        continue;
      }

      foreach ($section->get($field_name)->getValue() as $item) {
        if (!empty($item['target_id'])) {
          $item_ids[] = (int) $item['target_id'];
        }
      }
    }

    return array_values(array_unique($item_ids));
  }

  /**
   * Checks completion using achievements_learning completion storage.
   */
  protected function isItemCompletedByUser(int $uid, int $itemId): bool {
    $completed = achievements_storage_get('al:completed_items', $uid);
    if ($completed === FALSE) {
      return FALSE;
    }

    $completed = is_array($completed) ? $completed : [];
    return in_array($itemId, $completed, TRUE);
  }

}
