<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\views\EntityViewsData;

/**
 * Views data handler for Lesson Status entity type.
 */
final class LessonStatusViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();

    $data['lms_lesson_status']['lesson_revision__target_id']['relationship'] = [
      'base' => 'lms_lesson_field_data',
      'base field' => 'id',
      'id' => 'standard',
      'label' => $this->t('Lesson'),
    ];

    $data['lms_lesson_status']['activity_revisions__target_id']['relationship'] = [
      'base' => 'lms_activity_field_data',
      'base field' => 'id',
      'id' => 'standard',
      'label' => $this->t('Activity'),
    ];

    return $data;
  }

}
