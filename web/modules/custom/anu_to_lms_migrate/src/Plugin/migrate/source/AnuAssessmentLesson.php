<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\source;

use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Reads Anu assessments as LMS lessons with ordered supported activities.
 *
 * @MigrateSource(
 *   id = "anu_assessment_lesson"
 * )
 */
final class AnuAssessmentLesson extends SourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'nid' => $this->t('Assessment node ID'),
      'title' => $this->t('Assessment title'),
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
    return 'Anu LMS module_assessment nodes with supported activities';
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \Iterator {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'module_assessment')
      ->sort('nid')
      ->execute();

    foreach ($storage->loadMultiple($ids) as $assessment) {
      $activities = [];
      foreach ($assessment->get('field_module_assessment_items')->referencedEntities() as $item) {
        if (in_array($item->bundle(), [
          'lesson_text',
          'lesson_heading',
          'question_single_choice',
          'question_multi_choice',
        ], TRUE)) {
          $activities[] = ['paragraph_id' => (int) $item->id()];
          continue;
        }
        throw new MigrateException(sprintf(
          'Unsupported assessment item bundle %s in node %s (paragraph %s).',
          $item->bundle(),
          $assessment->id(),
          $item->id(),
        ));
      }
      if ($activities === []) {
        continue;
      }

      yield [
        'nid' => (int) $assessment->id(),
        'title' => (string) $assessment->label(),
        'activities' => $activities,
        'status' => (int) $assessment->isPublished(),
        'uid' => (int) ($assessment->getOwnerId() ?: 1),
        'created' => (int) $assessment->getCreatedTime(),
        'changed' => (int) $assessment->getChangedTime(),
      ];
    }
  }

}
