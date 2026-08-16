<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Membership form alter hooks logic.
 */
final class LmsClassesViewsHooks {

  use StringTranslationTrait;

  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    $data['group_relationship_field_data']['classes_filter'] = [
      'title' => $this->t('Filter relationships by child class'),
      'filter' => [
        'title' => $this->t('Is content of selected classes'),
        'real field' => 'gid',
        'id' => 'lms_parent_class',
      ],
      'argument' => [
        'title' => $this->t('Group relationships of Course classes'),
        'id' => 'course_classes_relationships',
        'real field' => 'gid',
      ],
    ];

    $data['group_relationship_field_data']['course_status'] = [
      'title' => $this->t('Course status'),
      'help' => $this->t('Relates memberships to the current Course Status entity. Requires Course (lms_course group bundle) context.'),
      'relationship' => [
        'label' => $this->t('Course status'),
        'group' => $this->t('LMS'),
        'real field' => 'gid',
        'base' => 'lms_course_status',
        'id' => 'class_member_to_course_status',
      ],
    ];
  }

}
