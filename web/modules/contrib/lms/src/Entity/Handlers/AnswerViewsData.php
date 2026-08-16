<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\views\EntityViewsData;

/**
 * Views data handler for Answer entity type.
 */
final class AnswerViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();

    $data['lms_answer']['activity_revision__target_id']['relationship'] = [
      'base' => 'lms_activity_field_data',
      'base field' => 'id',
      'id' => 'standard',
      'label' => $this->t('Activity'),
    ];

    return $data;
  }

}
