<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\lms\Entity\CourseStatus;
use Drupal\views\EntityViewsData;

/**
 * Views data handler for Course Status entity type.
 */
final class CourseStatusViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();

    // Class members support.
    $data['lms_course_status']['class_parent_or_current_group'] = [
      'help' => $this->t('Class parent group statuses for the class members only or course statuses'),
      'argument' => [
        'title' => $this->t('Class member or all classes` members statuses'),
        'id' => 'class_parent_or_current_group',
      ],
    ];

    // Override default status handlers.
    $data['lms_course_status']['status']['field']['id'] = 'lms_student_course_status';
    $data['lms_course_status']['status']['filter'] = [
      'id' => 'in_operator',
      'options callback' => [CourseStatus::class, 'getStatusOptions'],
    ];

    // Student results link field.
    $data['lms_course_status']['results_link'] = [
      'title' => $this->t('Student results link'),
      'help' => $this->t('Display a link to the student results if the course has been started.'),
      'field' => [
        'id' => 'lms_course_results_link',
        'real field' => 'id',
      ],
    ];

    // Course relationship.
    $data['lms_course_status']['course__target_id']['relationship'] = [
      'base' => 'groups_field_data',
      'base field' => 'id',
      'id' => 'standard',
      'label' => $this->t('Course'),
    ];

    // @todo Remove in the next minor when the obsolete field is removed.
    $data['lms_course_status']['gid']['deprecated'] = TRUE;
    $data['lms_course_status']['gid']['help'] = $this->t("Deprecated: Don't use this field, It's not used by Drupal LMS and subject to removal.");

    return $data;
  }

}
