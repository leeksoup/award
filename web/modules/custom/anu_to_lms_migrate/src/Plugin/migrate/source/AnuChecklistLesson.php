<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Reads Anu lessons and supported activities in page/content order.
 *
 * @MigrateSource(
 *   id = "anu_checklist_lesson"
 * )
 */
final class AnuChecklistLesson extends SourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'nid' => $this->t('Source lesson node ID'),
      'title' => $this->t('Lesson title'),
      'description' => $this->t('Lesson description'),
      'activities' => $this->t('Ordered supported activity paragraph IDs'),
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
    return 'Anu LMS module_lesson nodes containing supported activities';
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \Iterator {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'module_lesson')
      ->sort('nid')
      ->execute();

    foreach ($storage->loadMultiple($ids) as $lesson) {
      $activities = [];
      if ($lesson->hasField('field_module_lesson_content')) {
        foreach ($lesson->get('field_module_lesson_content')->referencedEntities() as $section) {
          if (!$section->hasField('field_lesson_section_content')) {
            continue;
          }
          foreach ($section->get('field_lesson_section_content')->referencedEntities() as $block) {
            if (!in_array($block->bundle(), [
              'lesson_text',
              'lesson_heading',
              'lesson_embedded_video',
              'lesson_audio',
              'lesson_checklist',
            ], TRUE)) {
              continue;
            }
            $activities[] = ['paragraph_id' => (int) $block->id()];
          }
        }
      }

      if ($activities === []) {
        continue;
      }

      yield [
        'nid' => (int) $lesson->id(),
        'title' => (string) $lesson->label(),
        'description' => '',
        'activities' => $activities,
        'status' => (int) $lesson->isPublished(),
        'uid' => (int) ($lesson->getOwnerId() ?: 1),
        'created' => (int) $lesson->getCreatedTime(),
        'changed' => (int) $lesson->getChangedTime(),
      ];
    }
  }

}
