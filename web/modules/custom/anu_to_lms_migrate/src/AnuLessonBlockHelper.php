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
    'question_single_choice',
    'question_multi_choice',
    'question_short_answer',
    'question_long_answer',
  ];

  /**
   * Assessment item bundles that migrate to LMS activity references.
   */
  private const ASSESSMENT_ACTIVITY_BUNDLES = [
    'lesson_text',
    'question_single_choice',
    'question_multi_choice',
    'question_short_answer',
    'question_long_answer',
  ];

  /**
   * Anu question bundles that map to the LMS free_text questions field.
   */
  private const FREE_TEXT_QUESTION_BUNDLES = [
    'question_short_answer',
    'question_long_answer',
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
   * Checks whether an Anu question wrapper maps to LMS free_text.
   */
  public static function isFreeTextQuestionBundle(string $bundle): bool {
    return in_array($bundle, self::FREE_TEXT_QUESTION_BUNDLES, TRUE);
  }

  /**
   * Builds a compact title from the first words of source text.
   */
  public static function compactTitle(
    string $text,
    string $fallback,
    int $word_count = 4,
  ): string {
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    if ($plain === '') {
      return $fallback;
    }

    $words = preg_split('/\s+/', $plain) ?: [];
    $title = trim(
      implode(' ', array_slice($words, 0, $word_count)),
      " \t\n\r\0\x0B.,;:!?()[]{}",
    );
    return $title === '' ? $fallback : mb_strimwidth($title, 0, 80, '...');
  }

  /**
   * Returns the 1-based ordinal of this block within its lesson for a bundle.
   */
  public static function lessonBundleOrdinal(
    ContentEntityInterface $activity,
    string $bundle,
  ): int {
    $lesson = self::parentLesson($activity);
    if (
      $lesson === NULL
      || !$lesson->hasField('field_module_lesson_content')
    ) {
      return 1;
    }

    $ordinal = 0;
    foreach ($lesson->get('field_module_lesson_content')->referencedEntities() as $section) {
      if (!$section->hasField('field_lesson_section_content')) {
        continue;
      }
      foreach ($section->get('field_lesson_section_content')->referencedEntities() as $item) {
        if ($item->bundle() !== $bundle) {
          continue;
        }
        $ordinal++;
        if ((int) $item->id() === (int) $activity->id()) {
          return $ordinal;
        }
      }
    }

    return 1;
  }

  /**
   * Returns the count of source blocks for a bundle within this block's lesson.
   */
  public static function lessonBundleCount(
    ContentEntityInterface $activity,
    string $bundle,
  ): int {
    $lesson = self::parentLesson($activity);
    if (
      $lesson === NULL
      || !$lesson->hasField('field_module_lesson_content')
    ) {
      return 0;
    }

    $count = 0;
    foreach ($lesson->get('field_module_lesson_content')->referencedEntities() as $section) {
      if (!$section->hasField('field_lesson_section_content')) {
        continue;
      }
      foreach ($section->get('field_lesson_section_content')->referencedEntities() as $item) {
        if ($item->bundle() === $bundle) {
          $count++;
        }
      }
    }

    return $count;
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
   * Returns the parent lesson node for an Anu lesson-section content block.
   */
  private static function parentLesson(ContentEntityInterface $activity): ?ContentEntityInterface {
    $parent_type = $activity->get('parent_type')->value ?? NULL;
    $parent_id = $activity->get('parent_id')->value ?? NULL;
    if (!$parent_type || !$parent_id) {
      return NULL;
    }

    $section = \Drupal::entityTypeManager()
      ->getStorage((string) $parent_type)
      ->load((int) $parent_id);
    if (!$section instanceof ContentEntityInterface) {
      return NULL;
    }
    if (
      !$section->hasField('parent_type')
      || !$section->hasField('parent_id')
    ) {
      return NULL;
    }

    $lesson_type = $section->get('parent_type')->value ?? NULL;
    $lesson_id = $section->get('parent_id')->value ?? NULL;
    if (!$lesson_type || !$lesson_id) {
      return NULL;
    }

    $lesson = \Drupal::entityTypeManager()
      ->getStorage((string) $lesson_type)
      ->load((int) $lesson_id);
    return $lesson instanceof ContentEntityInterface ? $lesson : NULL;
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
