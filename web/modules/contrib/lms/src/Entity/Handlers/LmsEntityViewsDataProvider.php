<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views\EntityViewsData;

/**
 * Provides Views data for LMS entity types (Activities, Lessons).
 */
final class LmsEntityViewsDataProvider extends EntityViewsData {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();

    $data[$this->entityType->getBaseTable()]['lms_select'] = [
      'title' => $this->t('LMS selection for adding to parent'),
      'help' => $this->t('Add a checkbox for every row - required to be able to add @label to parent entity in an entity form widget.', [
        '@label' => $this->entityType->getPluralLabel(),
      ]),
      'field' => [
        'id' => 'lms_entity_selection',
        'real field' => $this->entityType->getKey('id'),
      ],
    ];

    // Add parent entity filter.
    $this->addParentEntityFilter($data);

    // Add orphan filter.
    $this->addOrphanFilter($data);

    // Add reverse relationship to course (lms_lesson only).
    $this->addCourseRelationship($data);

    return $data;
  }

  /**
   * Add parent entity filter.
   */
  private function addParentEntityFilter(array &$data): void {
    $entity_type_id = $this->entityType->id();

    if ($entity_type_id === 'lms_lesson') {
      $data[$this->entityType->getDataTable()]['parent_course'] = [
        'title' => $this->t('Parent course'),
        'help' => $this->t('Filter lessons by their parent course.'),
        'real field' => $this->entityType->getKey('id'),
        'filter' => [
          'id' => 'lms_parent_entity',
          'ancestor_entity_type' => 'group',
        ],
      ];
    }
    elseif ($entity_type_id === 'lms_activity') {
      $data[$this->entityType->getDataTable()]['parent_course'] = [
        'title' => $this->t('Parent lesson'),
        'help' => $this->t('Filter activities by their parent lesson.'),
        'real field' => $this->entityType->getKey('id'),
        'filter' => [
          'id' => 'lms_parent_entity',
          'ancestor_entity_type' => 'lms_lesson',
        ],
      ];
      $data[$this->entityType->getDataTable()]['grandparent_course'] = [
        'title' => $this->t('Parent course'),
        'help' => $this->t('Filter activities by their grandparent course.'),
        'real field' => $this->entityType->getKey('id'),
        'filter' => [
          'id' => 'lms_parent_entity',
          'ancestor_entity_type' => 'group',
        ],
      ];
    }
    else {
      throw new \Exception('Handler assigned to an unsupported entity type.');
    }
  }

  /**
   * Add reverse relationship from lesson to the course(s) it belongs to.
   */
  private function addCourseRelationship(array &$data): void {
    if ($this->entityType->id() !== 'lms_lesson') {
      return;
    }

    $data[$this->entityType->getDataTable()]['reverse_lessons_group'] = [
      'title' => $this->t('Course'),
      'help' => $this->t('Relate the lesson to the course(s) it belongs to via the course lessons field.'),
      'relationship' => [
        'label' => $this->t('Course'),
        'group' => $this->entityType->getLabel(),
        'id' => 'entity_reverse',
        // Target entity: the group entity (courses table).
        'base' => 'groups_field_data',
        'entity_type' => 'group',
        'base field' => 'id',
        // Source field: group.lessons (stored in group__lessons).
        'field_name' => 'lessons',
        'field table' => 'group__lessons',
        'field field' => 'lessons_target_id',
        'join_extra' => [
          [
            'field' => 'deleted',
            'value' => 0,
            'numeric' => TRUE,
          ],
          [
            'field' => 'bundle',
            'value' => 'lms_course',
          ],
        ],
      ],
    ];
  }

  /**
   * Add orphan filter (entities not referenced by any direct parent).
   */
  private function addOrphanFilter(array &$data): void {
    $entity_type_id = $this->entityType->id();

    if ($entity_type_id === 'lms_lesson') {
      $data[$this->entityType->getDataTable()]['orphaned'] = [
        'title' => $this->t('Orphaned'),
        'help' => $this->t('Show only lessons not attached to any course.'),
        'real field' => $this->entityType->getKey('id'),
        'filter' => [
          'id' => 'lms_orphan',
          'parent_table' => 'group__lessons',
          'parent_column' => 'lessons_target_id',
        ],
      ];
    }
    elseif ($entity_type_id === 'lms_activity') {
      $data[$this->entityType->getDataTable()]['orphaned'] = [
        'title' => $this->t('Orphaned'),
        'help' => $this->t('Show only activities not attached to any lesson.'),
        'real field' => $this->entityType->getKey('id'),
        'filter' => [
          'id' => 'lms_orphan',
          'parent_table' => 'lms_lesson__activities',
          'parent_column' => 'activities_target_id',
        ],
      ];
    }
  }

}
