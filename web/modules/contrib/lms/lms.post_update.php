<?php

/**
 * @file
 * Contains post-update hooks for the LMS module.
 */

declare(strict_types=1);

use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;

/**
 * Set status to TRUE on all lessons and activities.
 */
function lms_post_update_set_statuses(array &$sandbox): void {
  $storages = [
    'lms_lesson' => \Drupal::entityTypeManager()->getStorage('lms_lesson'),
    'lms_activity' => \Drupal::entityTypeManager()->getStorage('lms_activity'),
  ];
  if (!\array_key_exists('list', $sandbox)) {
    $sandbox['list'] = [];
    foreach ($storages as $entity_type_id => $storage) {
      foreach ($storage->getQuery()->accessCheck(FALSE)->execute() as $id) {
        $sandbox['list'][] = [$entity_type_id, $id];
      }
    }
    $sandbox['total'] = \count($sandbox['list']);
    $sandbox['progress'] = 0;
  }

  $item = array_pop($sandbox['list']);
  if ($item === NULL) {
    return;
  }

  $entity = $storages[$item[0]]->load($item[1]);
  if ($entity instanceof EntityPublishedInterface) {
    $entity->setPublished()->save();
  }

  $sandbox['progress']++;
  $sandbox['#finished'] = $sandbox['progress'] / $sandbox['total'];
}

/**
 * BC - Add add students permission to all member course group roles.
 */
function lms_post_update_add_students_permission(): void {
  $roles = \Drupal::entityTypeManager()->getStorage('group_role')->loadByProperties([
    'group_type' => 'lms_course',
    'scope' => ['insider', 'individual'],
  ]);
  /** @var Drupal\group\Entity\GroupRoleInterface $role */
  foreach ($roles as $role) {
    if ($role->hasPermission('add students')) {
      continue;
    }
    $role->grantPermission('add students')->save();
  }
}

/**
 * BC - Add view students permission to all member course group roles.
 */
function lms_post_update_view_students_permission(): void {
  $roles = \Drupal::entityTypeManager()->getStorage('group_role')->loadByProperties([
    'group_type' => 'lms_course',
    'scope' => ['insider', 'individual'],
  ]);
  /** @var Drupal\group\Entity\GroupRoleInterface $role */
  foreach ($roles as $role) {
    if ($role->hasPermission('view students')) {
      continue;
    }
    $role->grantPermission('view students')->save();
  }
}

/**
 * BC - Add grade students permission to all member course group roles.
 */
function lms_post_update_grade_students_permission(): void {
  $roles = \Drupal::entityTypeManager()->getStorage('group_role')->loadByProperties([
    'group_type' => 'lms_course',
    'scope' => ['insider', 'individual'],
  ]);
  /** @var Drupal\group\Entity\GroupRoleInterface $role */
  foreach ($roles as $role) {
    if ($role->hasPermission('grade students')) {
      continue;
    }
    $role->grantPermission('grade students')->save();
  }
}

/**
 * Populate new revision reference fields from old entity reference fields.
 *
 * @phpstan-ignore-next-line
 */
function lms_post_update_populate_revision_references(array &$sandbox): ?string {
  $entity_type_manager = \Drupal::entityTypeManager();

  if (!\array_key_exists('progress', $sandbox)) {
    $sandbox['progress'] = 0;
    // Process in order: Courses, Lessons, CourseStatus, LessonStatus, Answer.
    $sandbox['max'] = 5;
    $sandbox['current_entity_type'] = 'group';
  }

  $batch_size = 50;
  $storage = $entity_type_manager->getStorage($sandbox['current_entity_type']);

  if (!\array_key_exists('entity_ids', $sandbox)) {
    $query = $storage->getQuery()->accessCheck(FALSE)->sort('id');
    if ($sandbox['current_entity_type'] === 'group') {
      $query->condition('type', 'lms_course');
    }
    $sandbox['entity_ids'] = $query->execute();
    $sandbox['total_for_type'] = \count($sandbox['entity_ids']);
    $sandbox['processed_for_type'] = 0;
  }

  $entity_ids_slice = \array_slice($sandbox['entity_ids'], $sandbox['processed_for_type'], $batch_size);

  if (\count($entity_ids_slice) > 0) {
    $entities_to_process = array_values($storage->loadMultiple($entity_ids_slice));

    foreach ($entities_to_process as $entity) {
      // First, create new revisions for Courses and Lessons to ensure their
      // own reference fields are populated in the revision table.
      if ($entity instanceof ContentEntityInterface && $entity instanceof RevisionLogInterface &&
        ($sandbox['current_entity_type'] === 'group' || $sandbox['current_entity_type'] === 'lms_lesson')) {
        $entity->setNewRevision(TRUE);
        $entity->setRevisionCreationTime(\Drupal::time()->getRequestTime());
        $entity->setRevisionLogMessage('Initial revision created during LMS upgrade to revisioning system.');
        $entity->save();
      }
      // Next, populate the new revision fields on progress entities.
      elseif ($entity instanceof ContentEntityInterface) {
        $field_map = [];
        if ($sandbox['current_entity_type'] === 'lms_course_status') {
          $field_map = ['gid' => 'course'];
        }
        elseif ($sandbox['current_entity_type'] === 'lms_lesson_status') {
          $field_map = ['lesson' => 'lesson_revision', 'activities' => 'activity_revisions'];
        }
        elseif ($sandbox['current_entity_type'] === 'lms_answer') {
          $field_map = ['activity' => 'activity_revision'];
        }

        $needs_save = FALSE;
        foreach ($field_map as $old_field => $new_field) {
          if (!$entity->get($old_field)->isEmpty() && $entity->get($new_field)->isEmpty()) {
            $new_field_values = [];
            foreach ($entity->get($old_field) as $item) {
              if ($item instanceof EntityReferenceItem && $item->entity instanceof RevisionableInterface) {
                $new_field_values[] = [
                  'target_id' => $item->target_id,
                  'vid' => $item->entity->getRevisionId(),
                ];
              }
            }
            if (\count($new_field_values) > 0) {
              $entity->set($new_field, $new_field_values);
              $needs_save = TRUE;
            }
          }
        }
        if ($needs_save) {
          $entity->save();
        }
      }
    }
    $sandbox['processed_for_type'] += \count($entity_ids_slice);
  }

  // Check if we are done with the current entity type.
  if ($sandbox['total_for_type'] === 0 || $sandbox['processed_for_type'] >= $sandbox['total_for_type']) {
    $sandbox['progress']++;
    unset($sandbox['entity_ids'], $sandbox['total_for_type'], $sandbox['processed_for_type']);

    // Determine the next entity type to process.
    if ($sandbox['progress'] === 1) {
      $sandbox['current_entity_type'] = 'lms_lesson';
    }
    elseif ($sandbox['progress'] === 2) {
      $sandbox['current_entity_type'] = 'lms_course_status';
    }
    elseif ($sandbox['progress'] === 3) {
      $sandbox['current_entity_type'] = 'lms_lesson_status';
    }
    elseif ($sandbox['progress'] === 4) {
      $sandbox['current_entity_type'] = 'lms_answer';
    }
  }

  if ($sandbox['progress'] >= $sandbox['max']) {
    $sandbox['#finished'] = 1;
    return (string) t('Populated all LMS revision reference fields with current revisions.');
  }
  else {
    $total_processed_for_type = $sandbox['processed_for_type'] ?? 0;
    $total_for_type = $sandbox['total_for_type'] ?? 1;
    if ($total_for_type === 0) {
      $total_for_type = 1;
    }
    $sandbox['#finished'] = ($sandbox['progress'] + ($total_processed_for_type / $total_for_type)) / $sandbox['max'];
  }
  return NULL;
}
