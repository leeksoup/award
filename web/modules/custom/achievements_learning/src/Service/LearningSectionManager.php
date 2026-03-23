<?php

namespace Drupal\achievements_learning\Service;

use Drupal\anu_lms\Lesson;
use Drupal\Core\Entity\EntityFieldManagerInterface;
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
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected Lesson $lessonService,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Determines whether a section is complete for a user.
   */
  public function isSectionComplete(int $uid, int $paragraphId): bool {
    $item_ids = $this->getSectionLessonsAndQuizzes($paragraphId);
    if ($item_ids === []) {
      return FALSE;
    }

    foreach ($item_ids as $item_id) {
      if (!$this->lessonService->isCompletedByUser($item_id, $uid)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Returns section IDs for a lesson.
   */
  public function getLessonSectionIds(int $lessonId): array {
    $query = $this->entityTypeManager->getStorage('paragraph')->getQuery()
      ->condition('type', 'course_modules')
      ->accessCheck(FALSE);

    $group = $query->orConditionGroup()
      ->condition('field_module_lessons', $lessonId);

<<<<<<< codex/find-requirements-for-drupal-sub-module-6vombf
    // Only add assessment field if it exists on at least one paragraph bundle.
    try {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('paragraph', 'course_modules');
=======
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    if ($paragraph_storage->getEntityType()->hasKey('bundle')) {
      // Nothing extra to do; keep query explicit for clarity.
    }

    if ($this->entityTypeManager->hasDefinition('paragraph')) {
      $query->condition($group);
    }

    // Only add assessment field if it exists on at least one paragraph bundle.
    try {
      $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('paragraph', 'course_modules');
>>>>>>> main
      if (isset($field_definitions['field_module_assessment'])) {
        $group->condition('field_module_assessment', $lessonId);
      }
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Unable to inspect course_modules paragraph field definitions: @message', ['@message' => $exception->getMessage()]);
    }

<<<<<<< codex/find-requirements-for-drupal-sub-module-6vombf
    $query->condition($group);
=======
>>>>>>> main
    return array_map('intval', array_values($query->execute()));
  }

  /**
   * Returns lesson and quiz IDs associated with a section paragraph.
   */
  public function getSectionLessonsAndQuizzes(int $paragraphId): array {
    /** @var \Drupal\paragraphs\ParagraphInterface|null $paragraph */
    $paragraph = $this->entityTypeManager->getStorage('paragraph')->load($paragraphId);
    if (!$paragraph || $paragraph->bundle() !== 'course_modules') {
      return [];
    }

    $item_ids = [];

    if ($paragraph->hasField('field_module_lessons')) {
      foreach ($paragraph->get('field_module_lessons')->getValue() as $item) {
        if (!empty($item['target_id'])) {
          $item_ids[] = (int) $item['target_id'];
        }
      }
    }

    if ($paragraph->hasField('field_module_assessment')) {
      foreach ($paragraph->get('field_module_assessment')->getValue() as $item) {
        if (!empty($item['target_id'])) {
          $item_ids[] = (int) $item['target_id'];
        }
      }
    }

    return array_values(array_unique($item_ids));
  }

}
