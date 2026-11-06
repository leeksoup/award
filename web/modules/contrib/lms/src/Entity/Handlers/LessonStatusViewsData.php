<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\lms\Entity\LessonStatusInterface;
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

    $data['lms_lesson_status'][LessonStatusInterface::LESSON_FIELD . '__target_id']['relationship'] = [
      'base' => 'lms_lesson_field_data',
      'base field' => 'id',
      'id' => 'standard',
      'label' => $this->t('Lesson'),
    ];

    $table_mapping = $this->storage->getTableMapping();
    $activity_revisions_table = $table_mapping->getFieldTableName(LessonStatusInterface::ACTIVITIES_FIELD);
    $activity_revisions_column = $table_mapping->getColumnNames(LessonStatusInterface::ACTIVITIES_FIELD)['target_id'];
    $data[$activity_revisions_table][$activity_revisions_column]['relationship'] = [
      'base' => 'lms_activity_field_data',
      'base field' => 'id',
      'id' => 'standard',
      'label' => $this->t('Activity'),
    ];

    return $data;
  }

}
