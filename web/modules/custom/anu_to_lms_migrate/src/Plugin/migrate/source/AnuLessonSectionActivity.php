<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\source;

use Drupal\anu_to_lms_migrate\AnuLessonBlockHelper;
use Drupal\anu_to_lms_migrate\VideoUrlNormalizer;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Reads supported non-checklist blocks from Anu lesson sections.
 *
 * @MigrateSource(
 *   id = "anu_lesson_section_activity"
 * )
 */
final class AnuLessonSectionActivity extends SourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'paragraph_id' => $this->t('Source block paragraph ID'),
      'activity_type' => $this->t('Target LMS activity bundle'),
      'name' => $this->t('Activity name'),
      'body' => $this->t('Formatted content body'),
      'video_url' => $this->t('Normalized video embed URL'),
      'audio_name' => $this->t('Accessible audio name'),
      'audio_file_id' => $this->t('Existing audio file ID'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds(): array {
    return ['paragraph_id' => ['type' => 'integer']];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return 'Supported Anu lesson section content blocks';
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \Iterator {
    $storage = \Drupal::entityTypeManager()->getStorage('paragraph');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $lesson_parent = $query->andConditionGroup()
      ->condition('parent_type', 'paragraph')
      ->condition('parent_field_name', 'field_lesson_section_content');
    $assessment_parent = $query->andConditionGroup()
      ->condition('parent_type', 'node')
      ->condition('parent_field_name', 'field_module_assessment_items');
    $parent = $query->orConditionGroup()
      ->condition($lesson_parent)
      ->condition($assessment_parent);

    $ids = $query->condition('type', [
        'lesson_text',
        'lesson_embedded_video',
        'lesson_audio',
      ], 'IN')
      ->condition($parent)
      ->sort('id')
      ->execute();

    foreach ($storage->loadMultiple($ids) as $block) {
      $row = [
        'paragraph_id' => (int) $block->id(),
        'activity_type' => 'content',
        'name' => 'Content ' . $block->id(),
        'body' => [],
        'video_url' => NULL,
        'audio_name' => NULL,
        'audio_file_id' => NULL,
      ];

      $heading = AnuLessonBlockHelper::headingForActivity($block);

      switch ($block->bundle()) {
        case 'lesson_text':
          $item = $block->get('field_lesson_text_content')->first();
          if ($item === NULL || trim((string) $item->value) === '') {
            continue 2;
          }
          $default_name = mb_strimwidth(trim(strip_tags((string) $item->value)), 0, 220, '…');
          $row['name'] = $heading ?? $default_name;
          $row['body'] = [[
            'value' => (string) $item->value,
            'format' => (string) ($item->format ?: 'minimal_html'),
          ]];
          break;

        case 'lesson_embedded_video':
          $source_url = (string) $block->get('field_lesson_embedded_video_url')->uri;
          $embed_url = VideoUrlNormalizer::normalize($source_url);
          if ($embed_url === NULL) {
            throw new MigrateException(sprintf(
              'Unsupported video URL in paragraph %s: %s',
              $block->id(),
              $source_url,
            ));
          }
          $row['activity_type'] = 'video';
          $row['name'] = $heading ?? 'Video ' . $block->id();
          $row['video_url'] = $embed_url;
          break;

        case 'lesson_audio':
          $file_id = $block->get('field_audio_file')->target_id;
          if (!$file_id || !\Drupal::entityTypeManager()->getStorage('file')->load($file_id)) {
            throw new MigrateException(sprintf(
              'Missing audio file in paragraph %s.',
              $block->id(),
            ));
          }
          $audio_name = trim((string) $block->get('field_audio_name')->value);
          $row['activity_type'] = 'audio';
          $row['name'] = $heading
            ?? ($audio_name !== '' ? $audio_name : 'Audio ' . $block->id());
          $row['audio_name'] = $row['name'];
          $row['audio_file_id'] = (int) $file_id;
          break;
      }

      yield $row;
    }
  }

}
