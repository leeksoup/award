<?php

/**
 * @file
 * Contains post-update hooks for the LMS module.
 */

declare(strict_types=1);

use Drupal\Component\Gettext\PoItem;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\locale\StringStorageInterface;

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

/**
 * Copies translations of reworded UI copy onto their new source strings.
 */
function lms_post_update_preserve_ui_copywriting_translations(): void {
  try {
    $storage = \Drupal::service('locale.storage');
  }
  catch (\Exception $e) {
    // Locale not installed, nothing to worry about.
    return;
  }

  $mappings = [
    'Invalid request.' => 'The requested course could not be retrieved because the request is invalid.',
    'Invalid lesson delta.' => [
      'The requested lesson could not be retrieved because the lesson number is invalid.',
      'The status of the requested lesson could not be retrieved because the lesson number is invalid.',
    ],
    'At least one lesson needs to be a part of the course.' => 'At least one lesson needs to be added to the course.',
    'Allow to revisit finished course' => 'Revisits after completion',
    'Always allow to go back to the training, navigate freely and correct answers without the need to restart.' => 'Allow students to re-enter the course after finishing. They can navigate freely, review content, and change answers without restarting the course.',
    'Check this to allow students to go back in a course or jump to any activity (always allowed when revisiting). Mandatory lessons are still required to pass but not to proceed to next lessons.' => 'Allow students to jump to any activity in the course; also enables a Back button to return to the previous activity. Mandatory lessons are still required to pass the course but do not have to be completed before proceeding to later lessons.',
    'Applied when assigning activities to a lesson.' => 'The default score this activity is marked out of when assigned to a lesson. Set to 0 if the activity does not require evaluation.',
    'Back' => 'Previous activity',
    'Submit' => 'Submit answer',
    'Required lesson not started' => 'A required earlier lesson must be started before you can access this one.',
    'Required lesson not finished' => 'A required earlier lesson must be finished before you can access this one.',
    'Required score on previous lesson not met' => 'A higher score on the previous lesson is required to unlock this content.',
    'This learning path has no lessons' => 'This course does not have any lessons yet. A course creator must add at least one.',
    'The requested lesson is not a part of the course' => 'This lesson has been removed from the course.',
    'The requested activity is not a part of the lesson' => 'This activity has been removed from the lesson.',
    'This lesson does not allow backwards navigation.' => 'This lesson does not allow returning to previous activities.',
    'This course does not allow free navigation.' => 'This course requires activities to be completed in order. Submit each activity to continue to the next one.',
    'This course awaits grading.' => 'This course is complete and awaiting grading. Check back later for your results.',
    'Not possible to go backwards.' => 'This course does not allow going back from this point.',
    'Access to course denied.' => 'This course could not be started. Your account may not have access to it.',
    'Incorrect activity requested.' => 'That activity cannot be accessed from here.',
    'Access denied.' => 'Your account does not have access to this course.',
    "The course doesn't exist anymore." => 'This course has been removed and is no longer available.',
    'Last activity completed, training still has unfinished activities.' => 'You have reached the end of the course, but some earlier activities are incomplete. Complete them, then return here to finish the course.',
    'Course completed, please wait for evaluation.' => 'Course completed! Your answers are awaiting teacher evaluation.',
    'Course completed.' => 'Course completed! You achieved a passing score.',
    'Course failed.' => 'Course completed. Your score did not meet the passing requirement, so the course is marked as failed.',
    'Allow to enter ungraded courses' => 'Re-entry to courses awaiting grading',
    'This allows students to reenter finished courses that still need manual grading by a teacher and make changes to their answers. NOTE: If changed on a site with existing courses that are already in progress, caches need to be cleared.' => 'This allows students to re-enter finished courses that still need manual grading by a teacher. Students may make changes to their answers.<br />NOTE: If changed on a site with existing courses in progress, <a href=":cache_url">caches need to be cleared</a>.',
    'When this is enabled and the current user starts a course, a lesson or an activity, the currently published revision is locked for the student and updates to those entities while the course is in progress do not affect the student experience in any way.<br />NOTE: cache clear is advised if changing this setting on a production site with courses in progress.<br />NOTE: course creators / editors are not affected and always see the current revisions.' => 'When this is enabled and a user starts a course, the exact current version of the course is locked in for that user. Making a new revision of the course, or any of its lessons and activities, will not affect the experience of current students in any way.<br />NOTE: A <a href=":cache_url">cache clear</a> is advised if changing this setting on a production site with courses in progress.<br />NOTE: course creators and editors will always see the latest revisions of course content, but the course navigation block is not updated unless their course progress is reset.',
    'Display a button in the course navigation block that gives course editors a 1-click reset of their course progress, so they can view the latest revisions. Not visible to students. NOTE: A <a href=":cache_url">cache clear</a> is required if changing this setting.' => 'Display a button in the course navigation block that gives course editors a 1-click reset of their course progress, so they can see the latest version. Not visible to students.<br />NOTE: A <a href=":cache_url">cache clear</a> is required for changes to this setting to take effect.',
    "If a student didn't get the score required to pass this lesson, it will be restarted when trying to navigate to the next one." => 'If a student does not achieve the required score on this lesson, the lesson restarts when they attempt to continue to the next one.',
    'After this much time has passed, the lesson will be finished with all remaining activities unanswered.' => 'After this much time has passed, the lesson is closed automatically and remaining activities are left unanswered. Set to 0 for no time limit.',
    'Max score' => 'Maximum score',
    'Setting this value to 0 will set all answers to the activity as evaluated.' => 'The score this activity is marked out of. Set to 0 if the activity does not require evaluation.',
    'This course has already been started by certain students, it is advised to create a new revision to avoid data inconsistency issues.' => 'Students are currently taking this course. Choose how your updates will affect them by checking or unchecking the "Create new revision" box below.',
    'This course has already been started by certain students, changing the field below may cause their current progress to become inconsistent with the new value.' => 'Students are currently taking this course. Making structural or scoring changes to the fields below may cause their progress to break. If you need to make changes for new enrollments without affecting current students, you can enable "Use revisions" in the <a href="@general_settings_url">LMS general settings</a>.',
    'Required score [%]' => 'Required score',
    'Time limit [min]' => 'Time limit',
    'No referenced @label_plural.' => 'No @label_plural have been added yet. Use the buttons below to create a new @label or add an existing one.',
    'Create @label' => 'Create new @label',
    'Reference @label' => 'Add existing @label',
    'Add references' => 'Add selected @label',
  ];

  foreach ($mappings as $old => $new) {
    foreach ((array) $new as $new_string) {
      _lms_post_update_copy_translation($storage, $old, $new_string);
    }
  }

  // formatPlural() sources are singular/plural joined by the delimiter.
  $old_plural_source = \implode(PoItem::DELIMITER, [
    'This lesson has already been started by certain students in the following course: @courses. Changing the field below may cause their current progress to become inconsistent with the new value.',
    'This lesson has already been started by certain students in the following courses: @courses. Changing the field below may cause their current progress to become inconsistent with the new value.',
  ]);
  $new_plural_source = \implode(PoItem::DELIMITER, [
    'This lesson has active students in the following course: @courses. Making structural or scoring changes to the field below may break their progress, unless you save with a new Revision.',
    'This lesson has active students in the following courses: @courses. Making structural or scoring changes to the field below may break their progress, unless you save with a new Revision.',
  ]);
  _lms_post_update_copy_translation($storage, $old_plural_source, $new_plural_source);
}

/**
 * Copies every existing translation of an old source string to a new one.
 */
function _lms_post_update_copy_translation(StringStorageInterface $storage, string $old_source, string $new_source): void {
  if ($old_source === $new_source) {
    return;
  }

  $old_string = $storage->findString(['source' => $old_source, 'context' => '']);
  if ($old_string === NULL) {
    return;
  }

  $new_string = $storage->findString(['source' => $new_source, 'context' => '']);
  if ($new_string === NULL) {
    $new_string = $storage->createString(['source' => $new_source, 'context' => ''])->save();
  }

  foreach ($storage->getTranslations(['lid' => $old_string->lid]) as $translation) {
    /** @var \Drupal\locale\TranslationString $translation */
    // Both these locale.storage methods LEFT JOIN against the source string,
    // so a string with no translation comes back as a stub, not NULL/empty.
    if ($translation->language === NULL || $translation->translation === NULL) {
      continue;
    }
    $existing = $storage->findTranslation([
      'lid' => $new_string->lid,
      'language' => $translation->language,
    ]);
    if ($existing !== NULL && !$existing->isNew()) {
      continue;
    }
    $storage->createTranslation([
      'lid' => $new_string->lid,
      'language' => $translation->language,
      'translation' => $translation->translation,
      'customized' => $translation->customized,
    ])->save();
  }
}
