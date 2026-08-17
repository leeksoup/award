<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Shared helpers for interpreting ordered Anu lesson/assessment blocks.
 */
final class AnuLessonBlockHelper {

  /**
   * Anu paragraph bundles that migrate to LMS activity references.
   */
  private const ACTIVITY_BUNDLES = [
    'lesson_text',
    'lesson_embedded_video',
    'lesson_audio',
    'lesson_checklist',
    'lesson_resource',
  ];

  /**
   * Assessment item bundles that migrate to LMS activity references.
   */
  private const ASSESSMENT_ACTIVITY_BUNDLES = [
    'lesson_text',
    'question_single_choice',
    'question_multi_choice',
  ];

  /**
   * Checks whether a lesson-section block should become an LMS activity.
   */
  public static function isLessonActivityBundle(string $bundle): bool {
    return in_array($bundle, self::ACTIVITY_BUNDLES, TRUE);
  }

  /**
   * Checks whether an assessment item should become an LMS activity.
   */
  public static function isAssessmentActivityBundle(string $bundle): bool {
    return in_array($bundle, self::ASSESSMENT_ACTIVITY_BUNDLES, TRUE);
  }

  /**
   * Returns the nearest immediately preceding Anu heading for an activity.
   */
  public static function headingForActivity(ContentEntityInterface $activity): ?string {
    $parent_type = $activity->get('parent_type')->value ?? NULL;
    $parent_id = $activity->get('parent_id')->value ?? NULL;
    $parent_field = $activity->get('parent_field_name')->value ?? NULL;
    if (!$parent_type || !$parent_id || !$parent_field) {
      return NULL;
    }

    $parent = \Drupal::entityTypeManager()
      ->getStorage((string) $parent_type)
      ->load((int) $parent_id);
    if (
      !$parent instanceof ContentEntityInterface
      || !$parent->hasField((string) $parent_field)
    ) {
      return NULL;
    }

    $pending_heading = NULL;
    foreach ($parent->get((string) $parent_field)->referencedEntities() as $item) {
      if ($item->bundle() === 'lesson_heading') {
        $heading = trim((string) $item->get('field_lesson_heading_value')->value);
        $pending_heading = $heading !== '' ? $heading : NULL;
        continue;
      }

      if ((int) $item->id() === (int) $activity->id()) {
        return $pending_heading;
      }

      $pending_heading = NULL;
    }

    return NULL;
  }

}
