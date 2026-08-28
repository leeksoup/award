<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\source;

use Drupal\anu_to_lms_migrate\AnuLessonBlockHelper;
use Drupal\anu_to_lms_migrate\VideoUrlNormalizer;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Reads Anu embedded-video paragraphs as core Remote Video media sources.
 *
 * @MigrateSource(
 *   id = "anu_remote_video"
 * )
 */
final class AnuRemoteVideo extends SourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'paragraph_id' => $this->t('Source video paragraph ID'),
      'name' => $this->t('Media name'),
      'video_url' => $this->t('Canonical Remote Video provider URL'),
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
    return 'Anu embedded videos';
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

    $ids = $query->condition('type', 'lesson_embedded_video')
      ->condition($parent)
      ->sort('id')
      ->execute();

    foreach ($storage->loadMultiple($ids) as $block) {
      $source_url = (string) $block->get('field_lesson_embedded_video_url')->uri;
      $video_url = VideoUrlNormalizer::normalize($source_url);
      if ($video_url === NULL) {
        throw new MigrateException(sprintf(
          'Unsupported video URL in paragraph %s: %s',
          $block->id(),
          $source_url,
        ));
      }

      yield [
        'paragraph_id' => (int) $block->id(),
        'name' => AnuLessonBlockHelper::headingForActivity($block) ?? 'Video ' . $block->id(),
        'video_url' => $video_url,
      ];
    }
  }

}
