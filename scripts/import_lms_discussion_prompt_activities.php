<?php

declare(strict_types=1);

/**
 * @file
 * Imports reviewed LMS discussion prompt activities from JSON.
 *
 * Usage (dry run, the default):
 *   drush php:script scripts/import_lms_discussion_prompt_activities.php
 *   drush php:script scripts/import_lms_discussion_prompt_activities.php \
 *     -- --input=/tmp/discussion-prompts.json
 *
 * Usage (apply as new revisions):
 *   drush php:script scripts/import_lms_discussion_prompt_activities.php \
 *     -- --apply --uid=123
 *
 * To import across matching databases with different current revisions, add:
 *   --allow-revision-mismatch
 *
 * DISCUSSION_PROMPT_IMPORT_PATH may also specify the input path. The default
 * is /tmp/lms-discussion-prompt-activities.json.
 *
 * Activities and linked discussion nodes are matched by UUID. Applying creates
 * new revisions and assigns --uid as revision author without changing entity
 * ownership. All records are validated before anything is saved.
 */

// Drush removes the script name and exposes arguments after `--` through the
// local $extra variable before including this file. Keep argv as a fallback so
// the parsing remains predictable when the script is executed another way.
$arguments = isset($extra) && is_array($extra)
  ? $extra
  : array_slice($_SERVER['argv'] ?? [], 1);
$input_path = getenv('DISCUSSION_PROMPT_IMPORT_PATH')
  ?: '/tmp/lms-discussion-prompt-activities.json';
$apply = in_array('--apply', $arguments, TRUE);
$allow_revision_mismatch = in_array('--allow-revision-mismatch', $arguments, TRUE);
$revision_uid = NULL;

foreach ($arguments as $argument) {
  if (str_starts_with($argument, '--input=')) {
    $input_path = substr($argument, strlen('--input='));
  }
  elseif (str_starts_with($argument, '--uid=')) {
    $revision_uid = substr($argument, strlen('--uid='));
  }
}

if ($input_path === '') {
  throw new \RuntimeException('The import input path cannot be empty.');
}
if ($apply && (!is_string($revision_uid)
  || !ctype_digit($revision_uid)
  || (int) $revision_uid < 1)) {
  throw new \RuntimeException(
    'Applying requires --uid=USER_ID, using a non-anonymous user ID.',
  );
}
if (!is_readable($input_path)) {
  throw new \RuntimeException(sprintf('Import file is not readable: %s', $input_path));
}

$contents = file_get_contents($input_path);
if ($contents === FALSE) {
  throw new \RuntimeException(sprintf('Unable to read import file: %s', $input_path));
}

$payload = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
if (!is_array($payload)
  || ($payload['export_type'] ?? NULL) !== 'lms_discussion_prompt_activities'
  || ($payload['schema_version'] ?? NULL) !== 1
  || !isset($payload['activities'])
  || !is_array($payload['activities'])) {
  throw new \RuntimeException(
    'The file is not a schema version 1 LMS discussion prompt activity export.',
  );
}

$entity_type_manager = \Drupal::entityTypeManager();
$activity_storage = $entity_type_manager->getStorage('lms_activity');
$node_storage = $entity_type_manager->getStorage('node');
$revision_author = NULL;

if ($apply) {
  $revision_author = $entity_type_manager->getStorage('user')->load((int) $revision_uid);
  if (!$revision_author instanceof \Drupal\user\UserInterface) {
    throw new \RuntimeException(sprintf(
      'Revision author user %d does not exist.',
      (int) $revision_uid,
    ));
  }
}

/**
 * Loads exactly one entity with a given UUID.
 *
 * @return \Drupal\Core\Entity\ContentEntityInterface|null
 *   The entity, or NULL when no entity has the UUID.
 */
$load_by_uuid = static function (
  \Drupal\Core\Entity\EntityStorageInterface $storage,
  string $uuid,
): ?\Drupal\Core\Entity\ContentEntityInterface {
  $entities = $storage->loadByProperties(['uuid' => $uuid]);
  if (count($entities) > 1) {
    throw new \RuntimeException(sprintf('UUID %s matched more than one entity.', $uuid));
  }

  $entity = reset($entities);
  return $entity instanceof \Drupal\Core\Entity\ContentEntityInterface
    ? $entity
    : NULL;
};

$errors = [];
$warnings = [];
$prepared_activities = [];
$prepared_discussions = [];
$seen_activity_uuids = [];
$seen_discussion_requests = [];
$unchanged_activities = 0;
$unchanged_discussions = 0;

foreach ($payload['activities'] as $index => $record) {
  $position = $index + 1;
  if (!is_array($record)) {
    $errors[] = sprintf('Record %d is not an object.', $position);
    continue;
  }

  $exported_id = $record['activity_id'] ?? NULL;
  $activity_uuid = $record['activity_uuid'] ?? NULL;
  $exported_revision_id = $record['activity_revision_id'] ?? NULL;

  if ((!is_int($exported_id) && !(is_string($exported_id) && ctype_digit($exported_id)))
    || (int) $exported_id < 1) {
    $errors[] = sprintf('Record %d has an invalid activity_id.', $position);
    continue;
  }
  $exported_id = (int) $exported_id;

  if (!is_string($activity_uuid) || $activity_uuid === '') {
    $errors[] = sprintf('Activity record %d has no activity_uuid.', $exported_id);
    continue;
  }
  if (isset($seen_activity_uuids[$activity_uuid])) {
    $errors[] = sprintf('Activity UUID %s appears more than once.', $activity_uuid);
    continue;
  }
  $seen_activity_uuids[$activity_uuid] = TRUE;

  if (!is_int($exported_revision_id)
    && !(is_string($exported_revision_id) && ctype_digit($exported_revision_id))) {
    $errors[] = sprintf('Activity %d has an invalid activity_revision_id.', $exported_id);
    continue;
  }
  if (($record['bundle'] ?? NULL) !== 'discussion_prompt') {
    $errors[] = sprintf('Activity %d is not marked as discussion_prompt.', $exported_id);
    continue;
  }
  if (!is_string($record['name'] ?? NULL)) {
    $errors[] = sprintf('Activity %d has an invalid name.', $exported_id);
    continue;
  }
  if (!is_string($record['discussion_title'] ?? NULL)) {
    $errors[] = sprintf('Activity %d has an invalid discussion_title.', $exported_id);
    continue;
  }
  if (!is_array($record['prompt_body'] ?? NULL)
    || !is_string($record['prompt_body']['value'] ?? NULL)
    || !is_string($record['prompt_body']['format'] ?? NULL)) {
    $errors[] = sprintf('Activity %d has an invalid prompt_body.', $exported_id);
    continue;
  }

  $activity = $load_by_uuid($activity_storage, $activity_uuid);
  if ($activity === NULL) {
    $errors[] = sprintf(
      'Activity UUID %s (exported ID %d) does not exist.',
      $activity_uuid,
      $exported_id,
    );
    continue;
  }
  $target_id = (int) $activity->id();
  if ($activity->bundle() !== 'discussion_prompt') {
    $errors[] = sprintf(
      'Activity UUID %s is now bundle %s, not discussion_prompt.',
      $activity_uuid,
      $activity->bundle(),
    );
    continue;
  }
  $expected_fields = [
    'field_discussion_title',
    'field_discussion_prompt_body',
    'field_discussion_node',
  ];
  foreach ($expected_fields as $field_name) {
    if (!$activity->hasField($field_name)) {
      $errors[] = sprintf('Activity %d does not have %s.', $target_id, $field_name);
    }
  }
  if (!$activity->hasField('field_discussion_title')
    || !$activity->hasField('field_discussion_prompt_body')
    || !$activity->hasField('field_discussion_node')) {
    continue;
  }

  if ((int) $activity->getRevisionId() !== (int) $exported_revision_id) {
    if (!$allow_revision_mismatch) {
      $errors[] = sprintf(
        'Activity %d revision differs: export %d, target %d. ' .
        'Re-export or use --allow-revision-mismatch.',
        $target_id,
        (int) $exported_revision_id,
        (int) $activity->getRevisionId(),
      );
      continue;
    }
    $warnings[] = sprintf(
      'Activity %d revision differs (export %d, target %d); allowed explicitly.',
      $target_id,
      (int) $exported_revision_id,
      (int) $activity->getRevisionId(),
    );
  }

  $current_body_values = $activity->get('field_discussion_prompt_body')->getValue();
  $current_body = [
    'value' => (string) ($current_body_values[0]['value'] ?? ''),
    'format' => (string) ($current_body_values[0]['format'] ?? ''),
  ];
  $new_body = [
    'value' => $record['prompt_body']['value'],
    'format' => $record['prompt_body']['format'],
  ];

  $activity_changes = [];
  if ((string) $activity->label() !== $record['name']) {
    $activity_changes[] = 'name';
  }
  if ((string) $activity->get('field_discussion_title')->value !== $record['discussion_title']) {
    $activity_changes[] = 'discussion_title';
  }
  if ($current_body !== $new_body) {
    $activity_changes[] = 'prompt_body';
  }

  if ($activity_changes === []) {
    $unchanged_activities++;
  }
  else {
    $prepared_activities[] = [
      'entity' => $activity,
      'id' => $target_id,
      'name' => $record['name'],
      'discussion_title' => $record['discussion_title'],
      'prompt_body' => $new_body,
      'changes' => $activity_changes,
    ];
  }

  $linked_record = $record['linked_discussion'] ?? NULL;
  $current_reference = $activity->get('field_discussion_node');
  if ($linked_record === NULL) {
    if (!$current_reference->isEmpty()) {
      $errors[] = sprintf(
        'Activity %d now has a linked discussion, but the export did not.',
        $target_id,
      );
    }
    continue;
  }
  if (!is_array($linked_record)
    || (!is_int($linked_record['node_id'] ?? NULL)
      && !(is_string($linked_record['node_id'] ?? NULL) && ctype_digit($linked_record['node_id'])))
    || !is_string($linked_record['uuid'] ?? NULL)
    || $linked_record['uuid'] === ''
    || (!is_int($linked_record['revision_id'] ?? NULL)
      && !(
        is_string($linked_record['revision_id'] ?? NULL)
        && ctype_digit($linked_record['revision_id'])
      ))
    || ($linked_record['bundle'] ?? NULL) !== 'discussion'
    || !is_string($linked_record['title'] ?? NULL)) {
    $errors[] = sprintf('Activity %d has invalid linked_discussion data.', $exported_id);
    continue;
  }
  if ($current_reference->isEmpty()) {
    $errors[] = sprintf('Activity %d no longer has its linked discussion.', $target_id);
    continue;
  }

  $discussion_uuid = $linked_record['uuid'];
  $discussion = $load_by_uuid($node_storage, $discussion_uuid);
  if (!$discussion instanceof \Drupal\node\NodeInterface) {
    $errors[] = sprintf(
      'Discussion UUID %s for activity %d does not exist.',
      $discussion_uuid,
      $target_id,
    );
    continue;
  }
  if ($discussion->bundle() !== 'discussion') {
    $errors[] = sprintf(
      'Node %d for activity %d is bundle %s, not discussion.',
      (int) $discussion->id(),
      $target_id,
      $discussion->bundle(),
    );
    continue;
  }
  if ((int) $current_reference->target_id !== (int) $discussion->id()) {
    $errors[] = sprintf(
      'Activity %d is no longer linked to discussion UUID %s.',
      $target_id,
      $discussion_uuid,
    );
    continue;
  }

  $requested_node_title = $linked_record['title'];
  if (isset($seen_discussion_requests[$discussion_uuid])) {
    if ($seen_discussion_requests[$discussion_uuid] !== $requested_node_title) {
      $errors[] = sprintf(
        'Shared discussion %d has conflicting requested titles in the import.',
        (int) $discussion->id(),
      );
    }
    continue;
  }
  $seen_discussion_requests[$discussion_uuid] = $requested_node_title;

  if ((int) $discussion->getRevisionId() !== (int) $linked_record['revision_id']) {
    if (!$allow_revision_mismatch) {
      $errors[] = sprintf(
        'Discussion node %d revision differs: export %d, target %d. ' .
        'Re-export or use --allow-revision-mismatch.',
        (int) $discussion->id(),
        (int) $linked_record['revision_id'],
        (int) $discussion->getRevisionId(),
      );
      continue;
    }
    $warnings[] = sprintf(
      'Discussion node %d revision differs (export %d, target %d); allowed explicitly.',
      (int) $discussion->id(),
      (int) $linked_record['revision_id'],
      (int) $discussion->getRevisionId(),
    );
  }

  if ((string) $discussion->label() === $requested_node_title) {
    $unchanged_discussions++;
    continue;
  }

  $prepared_discussions[$discussion_uuid] = [
    'entity' => $discussion,
    'id' => (int) $discussion->id(),
    'title' => $requested_node_title,
  ];
}

foreach ($warnings as $warning) {
  print "WARNING: $warning\n";
}
if ($errors !== []) {
  foreach ($errors as $error) {
    print "ERROR: $error\n";
  }
  throw new \RuntimeException(sprintf(
    'Import aborted: %d validation error(s); nothing was saved.',
    count($errors),
  ));
}

foreach ($prepared_activities as $item) {
  printf(
    "%s Activity %d: %s\n",
    $apply ? 'APPLY' : 'DRY RUN',
    $item['id'],
    implode(', ', $item['changes']),
  );
}
foreach ($prepared_discussions as $item) {
  printf(
    "%s Discussion node %d: title\n",
    $apply ? 'APPLY' : 'DRY RUN',
    $item['id'],
  );
}

if (!$apply) {
  printf(
    "Dry run complete: %d activity change(s), " .
    "%d discussion title change(s); %d activities and " .
    "%d unique discussions unchanged. " .
    "Re-run with -- --apply --uid=USER_ID to save.\n",
    count($prepared_activities),
    count($prepared_discussions),
    $unchanged_activities,
    $unchanged_discussions,
  );
  return;
}

printf(
  "Applying new revisions as user %d (%s). Entity ownership will not change.\n",
  (int) $revision_uid,
  $revision_author->label(),
);

foreach ($prepared_discussions as $item) {
  /** @var \Drupal\node\NodeInterface $discussion */
  $discussion = $item['entity'];
  $discussion->setTitle($item['title']);
  $discussion->setNewRevision(TRUE);
  $discussion->setRevisionUserId((int) $revision_uid);
  $discussion->setRevisionLogMessage(sprintf(
    'Discussion prompt batch import from %s.',
    basename($input_path),
  ));
  $discussion->save();
}

foreach ($prepared_activities as $item) {
  /** @var \Drupal\lms\Entity\ActivityInterface $activity */
  $activity = $item['entity'];
  $activity->set('name', $item['name']);
  $activity->set('field_discussion_title', $item['discussion_title']);
  $activity->set('field_discussion_prompt_body', [$item['prompt_body']]);
  $activity->setNewRevision(TRUE);
  $activity->setRevisionUserId((int) $revision_uid);
  $activity->setRevisionLogMessage(sprintf(
    'Discussion prompt batch import from %s.',
    basename($input_path),
  ));
  $activity->save();
}

printf(
  "Applied %d activity change(s) and %d discussion title change(s); " .
  "%d activities and %d unique discussions were unchanged.\n",
  count($prepared_activities),
  count($prepared_discussions),
  $unchanged_activities,
  $unchanged_discussions,
);
