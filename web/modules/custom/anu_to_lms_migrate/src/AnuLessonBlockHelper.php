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
   * Source blocks ignored when associating headings/resources to activities.
   */
  private const TRANSPARENT_BUNDLES = [
    'lesson_divider',
    'lesson_image',
    'lesson_image_thumbnail',
    'lesson_image_wide',
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
    $siblings = self::orderedSiblings($activity);
    if ($siblings === []) {
      return NULL;
    }

    $pending_heading = NULL;
    foreach ($siblings as $item) {
      if ($item->bundle() === 'lesson_heading') {
        $heading = trim((string) $item->get('field_lesson_heading_value')->value);
        $pending_heading = $heading !== '' ? $heading : NULL;
        continue;
      }

      if ((int) $item->id() === (int) $activity->id()) {
        return $pending_heading;
      }

      if (self::isTransparentBundle($item->bundle())) {
        continue;
      }

      $pending_heading = NULL;
    }

    return NULL;
  }

  /**
   * Returns source resource paragraphs that belong to a checklist activity.
   */
  public static function followingResourcesForChecklist(
    ContentEntityInterface $checklist,
  ): array {
    $siblings = self::orderedSiblings($checklist);
    if ($siblings === []) {
      return [];
    }

    $found_checklist = FALSE;
    $resources = [];
    foreach ($siblings as $item) {
      if (!$found_checklist) {
        $found_checklist = (int) $item->id() === (int) $checklist->id();
        continue;
      }

      if (self::isTransparentBundle($item->bundle())) {
        continue;
      }

      if ($item->bundle() === 'lesson_resource') {
        $resources[] = $item;
        continue;
      }

      break;
    }

    return $resources;
  }

  /**
   * Checks whether a source bundle is ignored for adjacency decisions.
   */
  private static function isTransparentBundle(string $bundle): bool {
    return in_array($bundle, self::TRANSPARENT_BUNDLES, TRUE);
  }

  /**
   * Returns the ordered sibling paragraph list for an Anu content block.
   */
  private static function orderedSiblings(ContentEntityInterface $activity): array {
    $parent_type = $activity->get('parent_type')->value ?? NULL;
    $parent_id = $activity->get('parent_id')->value ?? NULL;
    $parent_field = $activity->get('parent_field_name')->value ?? NULL;
    if (!$parent_type || !$parent_id || !$parent_field) {
      return [];
    }

    $parent = \Drupal::entityTypeManager()
      ->getStorage((string) $parent_type)
      ->load((int) $parent_id);
    if (
      !$parent instanceof ContentEntityInterface
      || !$parent->hasField((string) $parent_field)
    ) {
      return [];
    }

    return $parent->get((string) $parent_field)->referencedEntities();
  }

}
