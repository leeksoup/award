<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\source;

use Drupal\anu_to_lms_migrate\AnuLessonBlockHelper;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Reads current Anu lesson_checklist paragraph revisions from this site.
 *
 * @MigrateSource(
 *   id = "anu_lesson_checklist"
 * )
 */
final class AnuLessonChecklist extends SourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'paragraph_id' => $this->t('Checklist paragraph ID'),
      'title' => $this->t('Generated activity title'),
      'items' => $this->t('Ordered checklist item content'),
      'resources' => $this->t('Resource links attached after this checklist'),
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
    return ['paragraph_id' => ['type' => 'integer']];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return 'Anu LMS lesson_checklist paragraphs';
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \Iterator {
    $storage = \Drupal::entityTypeManager()->getStorage('paragraph');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'lesson_checklist')
      ->sort('id')
      ->execute();

    foreach ($storage->loadMultiple($ids) as $checklist) {
      $items = [];
      if ($checklist->hasField('field_checklist_items')) {
        foreach ($checklist->get('field_checklist_items')->referencedEntities() as $delta => $item) {
          $text = $item->hasField('field_checkbox_option')
            ? (string) $item->get('field_checkbox_option')->value
            : '';
          $description = $item->hasField('field_lesson_text_content')
            ? (string) $item->get('field_lesson_text_content')->value
            : '';
          $items[] = [
            'delta' => $delta,
            'text' => $text,
            'description' => $description,
          ];
        }
      }

      $plain_title = trim(strip_tags((string) ($items[0]['text'] ?? '')));
      $title = $plain_title === ''
        ? 'Checklist ' . $checklist->id()
        : 'Checklist: ' . mb_strimwidth($plain_title, 0, 220, '…');
      $heading = AnuLessonBlockHelper::headingForActivity($checklist);
      $resources = [];
      foreach (
        AnuLessonBlockHelper::followingResourcesForChecklist($checklist)
        as $resource
      ) {
        $resources[] = $this->buildResourceLink($resource);
      }

      yield [
        'paragraph_id' => (int) $checklist->id(),
        'title' => (string) ($heading ?? $title),
        'items' => $items,
        'resources' => $resources,
        'status' => 1,
        'uid' => 1,
        'created' => $checklist->hasField('created')
          ? (int) $checklist->get('created')->value
          : 0,
        'changed' => $checklist->hasField('changed')
          ? (int) $checklist->get('changed')->value
          : 0,
      ];
    }
  }

  /**
   * Builds a renderable resource link payload from an Anu resource paragraph.
   */
  private function buildResourceLink(ContentEntityInterface $resource): array {
    $media = $resource->get('field_resource_file')->entity;
    $file_id = $media instanceof ContentEntityInterface
      && $media->hasField('field_media_document')
      ? $media->get('field_media_document')->target_id
      : NULL;

    $file = $file_id
      ? \Drupal::entityTypeManager()->getStorage('file')->load($file_id)
      : NULL;
    if ($file === NULL) {
      throw new MigrateException(sprintf(
        'Missing resource document file in paragraph %s.',
        $resource->id(),
      ));
    }

    $label = trim((string) $resource->get('field_resource_name')->value);
    if ($label === '') {
      throw new MigrateException(sprintf(
        'Missing resource name in paragraph %s.',
        $resource->id(),
      ));
    }

    return [
      'paragraph_id' => (int) $resource->id(),
      'label' => $label,
      'description' => (string) $resource
        ->get('field_resource_description')->value,
      'url' => \Drupal::service('file_url_generator')
        ->generateString($file->getFileUri()),
    ];
  }

}
