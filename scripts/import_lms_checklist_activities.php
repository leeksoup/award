<?php

declare(strict_types=1);

/**
 * @file
 * Imports reviewed LMS checklist activities from the JSON export.
 *
 * Usage (dry run, the default):
 *   drush php:script scripts/import_lms_checklist_activities.php
 *
 * Usage (apply changes):
 *   drush php:script scripts/import_lms_checklist_activities.php \
 *     -- --apply --revision-uid=123
 *
 * To import an export made on another environment, allow differing activity
 * revision IDs explicitly:
 *   drush php:script scripts/import_lms_checklist_activities.php \
 *     -- --allow-revision-mismatch
 *
 * Set CHECKLIST_IMPORT_PATH to choose a different input file. For example:
 *   CHECKLIST_IMPORT_PATH=/tmp/checklists.json \
 *     drush php:script scripts/import_lms_checklist_activities.php -- --apply
 *
 * The importer changes only the activity name and field_checklist_body. It
 * validates all records before saving any entity. Applying requires a
 * non-anonymous revision author; it does not change the activity owner.
 */

$input_path = getenv('CHECKLIST_IMPORT_PATH') ?: '/tmp/lms-checklist-activities.json';
$arguments = $_SERVER['argv'] ?? [];
$apply = in_array('--apply', $arguments, TRUE);
$allow_revision_mismatch = in_array('--allow-revision-mismatch', $arguments, TRUE);
$revision_uid = NULL;

foreach ($arguments as $argument) {
  if (str_starts_with($argument, '--revision-uid=')) {
    $revision_uid = substr($argument, strlen('--revision-uid='));
  }
}

if ($apply && (!is_string($revision_uid) || !ctype_digit($revision_uid) || (int) $revision_uid < 1)) {
  throw new \RuntimeException('Applying requires --revision-uid=USER_ID, using a non-anonymous user ID.');
}

if (!is_readable($input_path)) {
  throw new \RuntimeException(sprintf('Import file is not readable: %s', $input_path));
}

$payload = json_decode(file_get_contents($input_path), TRUE, 512, JSON_THROW_ON_ERROR);
if (!is_array($payload)
  || ($payload['export_type'] ?? NULL) !== 'lms_checklist_activities'
  || ($payload['schema_version'] ?? NULL) !== 1
  || !isset($payload['activities'])
  || !is_array($payload['activities'])) {
  throw new \RuntimeException('The file is not a schema version 1 LMS checklist activity export.');
}

$activity_storage = \Drupal::entityTypeManager()->getStorage('lms_activity');
$revision_author = NULL;
if ($apply) {
  $revision_author = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->load((int) $revision_uid);
  if ($revision_author === NULL) {
    throw new \RuntimeException(sprintf('Revision author user %d does not exist.', (int) $revision_uid));
  }
}

$errors = [];
$prepared = [];
$unchanged = 0;
$seen_ids = [];

foreach ($payload['activities'] as $index => $record) {
  $position = $index + 1;
  if (!is_array($record)) {
    $errors[] = sprintf('Record %d is not an object.', $position);
    continue;
  }

  $id = $record['id'] ?? NULL;
  if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
    $errors[] = sprintf('Record %d has an invalid id.', $position);
    continue;
  }
  $id = (int) $id;

  if (isset($seen_ids[$id])) {
    $errors[] = sprintf('Activity %d appears more than once in the import file.', $id);
    continue;
  }
  $seen_ids[$id] = TRUE;

  if (($record['bundle'] ?? NULL) !== 'checklist') {
    $errors[] = sprintf('Activity %d is not marked as a checklist bundle.', $id);
    continue;
  }
  if (!is_string($record['uuid'] ?? NULL) || $record['uuid'] === '') {
    $errors[] = sprintf('Activity %d has no UUID.', $id);
    continue;
  }
  if (!is_int($record['revision_id'] ?? NULL) && !(is_string($record['revision_id'] ?? NULL) && ctype_digit($record['revision_id']))) {
    $errors[] = sprintf('Activity %d has an invalid revision_id.', $id);
    continue;
  }
  if (!is_string($record['name'] ?? NULL)) {
    $errors[] = sprintf('Activity %d has an invalid name.', $id);
    continue;
  }
  if (!is_array($record['body'] ?? NULL)
    || !is_string($record['body']['value'] ?? NULL)
    || !is_string($record['body']['format'] ?? NULL)) {
    $errors[] = sprintf('Activity %d has an invalid body value or text format.', $id);
    continue;
  }

  /** @var \Drupal\Core\Entity\ContentEntityInterface|null $activity */
  $activity = $activity_storage->load($id);
  if ($activity === NULL) {
    $errors[] = sprintf('Activity %d no longer exists.', $id);
    continue;
  }
  if ($activity->bundle() !== 'checklist') {
    $errors[] = sprintf('Activity %d is now bundle %s, not checklist.', $id, $activity->bundle());
    continue;
  }
  if ($activity->uuid() !== $record['uuid']) {
    $errors[] = sprintf('Activity %d UUID does not match the export.', $id);
    continue;
  }
  if ((int) $activity->getRevisionId() !== (int) $record['revision_id']) {
    if (!$allow_revision_mismatch) {
      $errors[] = sprintf('Activity %d was revised after export; re-export it before importing.', $id);
      continue;
    }
    printf(
      "WARNING: Activity %d revision differs (exported_revision %d, target_revision %d); allowed by --allow-revision-mismatch.\n",
      $id,
      (int) $record['revision_id'],
      (int) $activity->getRevisionId(),
    );
  }
  if (!$activity->hasField('field_checklist_body')) {
    $errors[] = sprintf('Activity %d does not have field_checklist_body.', $id);
    continue;
  }

  $current_body_values = $activity->get('field_checklist_body')->getValue();
  $current_body = [
    'value' => (string) ($current_body_values[0]['value'] ?? ''),
    'format' => (string) ($current_body_values[0]['format'] ?? ''),
  ];
  $new_body = [
    'value' => $record['body']['value'],
    'format' => $record['body']['format'],
  ];

  $changes = [];
  if ((string) $activity->label() !== $record['name']) {
    $changes[] = 'name';
  }
  if ($current_body !== $new_body) {
    $changes[] = 'body';
  }

  if ($changes === []) {
    $unchanged++;
    continue;
  }

  $prepared[] = [
    'activity' => $activity,
    'id' => $id,
    'name' => $record['name'],
    'body' => $new_body,
    'changes' => $changes,
  ];
}

if ($errors !== []) {
  foreach ($errors as $error) {
    print "ERROR: $error\n";
  }
  throw new \RuntimeException(sprintf(
    'Import aborted: %d validation error(s); no activities were saved.',
    count($errors),
  ));
}

foreach ($prepared as $item) {
  printf("%s Activity %d: %s\n", $apply ? 'APPLY' : 'DRY RUN', $item['id'], implode(', ', $item['changes']));
}

if (!$apply) {
  printf(
    "Dry run complete: %d change(s), %d unchanged. Re-run with -- --apply to save.\n",
    count($prepared),
    $unchanged,
  );
  return;
}

printf(
  "Applying new revisions as user %d (%s). Activity ownership will not change.\n",
  (int) $revision_uid,
  $revision_author->label(),
);

foreach ($prepared as $item) {
  /** @var \Drupal\Core\Entity\ContentEntityInterface $activity */
  $activity = $item['activity'];
  $activity->set('name', $item['name']);
  $activity->set('field_checklist_body', [$item['body']]);

  // Checklist activities are revisionable. Preserve the previous revision
  // and make the import traceable in the entity's revision history.
  if ($activity instanceof \Drupal\Core\Entity\RevisionableInterface) {
    $activity->setNewRevision(TRUE);
  }
  if ($activity instanceof \Drupal\Core\Entity\RevisionLogInterface) {
    $activity->setRevisionUserId((int) $revision_uid);
    $activity->setRevisionLogMessage(sprintf(
      'Checklist batch import from %s.',
      basename($input_path),
    ));
  }
  $activity->save();
}

printf("Applied %d change(s); %d activity record(s) were unchanged.\n", count($prepared), $unchanged);
