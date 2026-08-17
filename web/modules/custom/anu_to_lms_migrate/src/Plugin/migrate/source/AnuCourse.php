<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\source;

use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Reads Anu courses with their module lessons flattened in source order.
 *
 * @MigrateSource(
 *   id = "anu_course"
 * )
 */
final class AnuCourse extends SourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'nid' => $this->t('Source course node ID'),
      'label' => $this->t('Course title'),
      'description_value' => $this->t('Course description value'),
      'description_format' => $this->t('Course description text format'),
      'linear_progress' => $this->t('Whether Anu requires linear progress'),
      'lessons' => $this->t('Ordered lesson node IDs flattened across modules'),
      'status' => $this->t('Published status'),
      'uid' => $this->t('Author ID'),
      'created' => $this->t('Created timestamp'),
      'changed' => $this->t('Changed timestamp'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds(): array {
    return ['nid' => ['type' => 'integer']];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return 'Anu LMS course nodes with ordered module lessons';
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \Iterator {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->sort('nid')
      ->execute();

    foreach ($storage->loadMultiple($ids) as $course) {
      $lessons = [];

      if ($course->hasField('field_course_module')) {
        foreach ($course->get('field_course_module') as $module_item) {
          $module = $module_item->entity;
          if ($module === NULL) {
            throw new MigrateException(sprintf(
              'Anu course %d references a missing course module paragraph.',
              $course->id(),
            ));
          }
          if (!$module->hasField('field_module_lessons')) {
            throw new MigrateException(sprintf(
              'Anu course %d module paragraph %d has no field_module_lessons field.',
              $course->id(),
              $module->id(),
            ));
          }

          foreach ($module->get('field_module_lessons') as $lesson_item) {
            if ($lesson_item->entity === NULL) {
              throw new MigrateException(sprintf(
                'Anu course %d module paragraph %d references missing lesson node %s.',
                $course->id(),
                $module->id(),
                $lesson_item->target_id ?? 'unknown',
              ));
            }
            $lessons[] = ['nid' => (int) $lesson_item->target_id];
          }
        }
      }

      if ($lessons === []) {
        throw new MigrateException(sprintf(
          'Anu course %d has no lessons; Drupal LMS requires at least one lesson per course.',
          $course->id(),
        ));
      }

      $description = $course->hasField('field_course_description')
        ? $course->get('field_course_description')->first()
        : NULL;

      yield [
        'nid' => (int) $course->id(),
        'label' => (string) $course->label(),
        'description_value' => (string) ($description?->value ?? ''),
        'description_format' => (string) ($description?->format ?? 'minimal_html'),
        'linear_progress' => (int) ($course->get('field_course_linear_progress')->value ?? 0),
        'lessons' => $lessons,
        'status' => (int) $course->isPublished(),
        'uid' => (int) ($course->getOwnerId() ?: 1),
        'created' => (int) $course->getCreatedTime(),
        'changed' => (int) $course->getChangedTime(),
      ];
    }
  }

}
