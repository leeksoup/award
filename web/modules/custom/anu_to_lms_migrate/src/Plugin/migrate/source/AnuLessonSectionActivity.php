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
   * Anu block bundles supported by this activity migration.
   */
  private const SUPPORTED_BUNDLES = [
    'lesson_text',
    'lesson_embedded_video',
    'lesson_audio',
  ];

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'paragraph_id' => $this->t('Source block paragraph ID'),
      'activity_type' => $this->t('Target LMS activity bundle'),
      'name' => $this->t('Activity name'),
      'body' => $this->t('Formatted content body'),
      'remote_video_paragraph_id' => $this->t('Source Remote Video paragraph ID'),
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
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $rows = [];

    $lesson_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'module_lesson')
      ->sort('nid')
      ->execute();
    foreach ($node_storage->loadMultiple($lesson_ids) as $lesson) {
      if (!$lesson->hasField('field_module_lesson_content')) {
        continue;
      }
      foreach ($lesson->get('field_module_lesson_content')->referencedEntities() as $section) {
        if (!$section->hasField('field_lesson_section_content')) {
          continue;
        }
        $rows += $this->rowsFromSequence(
          $section->get('field_lesson_section_content')->referencedEntities(),
        );
      }
    }

    $assessment_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'module_assessment')
      ->sort('nid')
      ->execute();
    foreach ($node_storage->loadMultiple($assessment_ids) as $assessment) {
      if (!$assessment->hasField('field_module_assessment_items')) {
        continue;
      }
      $rows += $this->rowsFromSequence(
        $assessment->get('field_module_assessment_items')->referencedEntities(),
      );
    }

    foreach ($rows as $row) {
      yield $row;
    }
  }

  /**
   * Builds migration rows from a current paragraph reference sequence.
   */
  private function rowsFromSequence(array $blocks): array {
    $rows = [];
    foreach ($blocks as $block) {
      if (!in_array($block->bundle(), self::SUPPORTED_BUNDLES, TRUE)) {
        continue;
      }

      $row = [
        'paragraph_id' => (int) $block->id(),
        'activity_type' => 'content',
        'name' => 'Content ' . $block->id(),
        'body' => [],
        'remote_video_paragraph_id' => NULL,
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
          $row['name'] = $heading ?? AnuLessonBlockHelper::compactTitle(
            (string) $item->value,
            'Content ' . $block->id(),
          );
          $row['body'] = [[
            'value' => (string) $item->value,
            'format' => 'filtered_html',
          ]];
          break;

        case 'lesson_embedded_video':
          $source_url = (string) $block->get('field_lesson_embedded_video_url')->uri;
          if (VideoUrlNormalizer::normalize($source_url) === NULL) {
            throw new MigrateException(sprintf(
              'Unsupported video URL in paragraph %s: %s',
              $block->id(),
              $source_url,
            ));
          }
          $row['activity_type'] = 'video';
          $video_count = AnuLessonBlockHelper::lessonBundleCount(
            $block,
            'lesson_embedded_video',
          );
          $video_name = $video_count === 1
            ? 'Video'
            : 'Video ' . AnuLessonBlockHelper::lessonBundleOrdinal(
              $block,
              'lesson_embedded_video',
            );
          $row['name'] = $heading ?? $video_name;
          $row['remote_video_paragraph_id'] = (int) $block->id();
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

      $rows[(int) $block->id()] = $row;
    }

    return $rows;
  }

}
