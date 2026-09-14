<?php

declare(strict_types=1);

/**
 * @file
 * Splits accidentally shared Discussion nodes using an edited activity export.
 *
 * Before running, set a unique, nonblank discussion_title for every activity
 * in each accidental shared_discussions group in the export JSON.
 *
 * Usage (dry run, the default):
 *   drush php:script scripts/repair_shared_lms_discussion_prompt_nodes.php
 *
 * Usage (apply):
 *   drush php:script scripts/repair_shared_lms_discussion_prompt_nodes.php \
 *     -- --apply --uid=123
 *
 * A different input file may be selected with --input=/path/file.json or the
 * DISCUSSION_PROMPT_IMPORT_PATH environment variable. Revision mismatches stop
 * the repair unless --allow-revision-mismatch is supplied explicitly.
 */

$arguments = $_SERVER['argv'] ?? [];
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

if ($apply && (!is_string($revision_uid)
  || !ctype_digit($revision_uid)
  || (int) $revision_uid < 1)) {
  throw new \RuntimeException(
    'Applying requires --uid=USER_ID, using a non-anonymous user ID.',
  );
}
if (!is_readable($input_path)) {
  throw new \RuntimeException(sprintf('Repair input is not readable: %s', $input_path));
}

$contents = file_get_contents($input_path);
if ($contents === FALSE) {
  throw new \RuntimeException(sprintf('Unable to read repair input: %s', $input_path));
}

$payload = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
if (!is_array($payload)
  || ($payload['export_type'] ?? NULL) !== 'lms_discussion_prompt_activities'
  || ($payload['schema_version'] ?? NULL) !== 1
  || !is_array($payload['activities'] ?? NULL)) {
  throw new \RuntimeException(
    'The file is not a schema version 1 LMS discussion prompt activity export.',
  );
}

$entity_type_manager = \Drupal::entityTypeManager();
$activity_storage = $entity_type_manager->getStorage('lms_activity');
$node_storage = $entity_type_manager->getStorage('node');
$comment_storage = $entity_type_manager->getStorage('comment');
$user_storage = $entity_type_manager->getStorage('user');

if ($apply && !$user_storage->load((int) $revision_uid) instanceof \Drupal\user\UserInterface) {
  throw new \RuntimeException(sprintf(
    'Revision author user %d does not exist.',
    (int) $revision_uid,
  ));
}

/**
 * Loads exactly one content entity by UUID.
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

// Group exported activity records by their linked Discussion node UUID.
$groups = [];
$errors = [];
foreach ($payload['activities'] as $index => $record) {
  if (!is_array($record)) {
    $errors[] = sprintf('Record %d is not an object.', $index + 1);
    continue;
  }

  $linked = $record['linked_discussion'] ?? NULL;
  if ($linked === NULL) {
    continue;
  }
  if (!is_array($linked)
    || !is_string($linked['uuid'] ?? NULL)
    || $linked['uuid'] === '') {
    $errors[] = sprintf('Record %d has invalid linked_discussion data.', $index + 1);
    continue;
  }

  $groups[$linked['uuid']][] = $record;
}

$groups = array_filter(
  $groups,
  static fn (array $records): bool => count($records) > 1,
);

$plans = [];
foreach ($groups as $discussion_uuid => $records) {
  $discussion = $load_by_uuid($node_storage, $discussion_uuid);
  if (!$discussion instanceof \Drupal\node\NodeInterface
    || $discussion->bundle() !== 'discussion') {
    $errors[] = sprintf(
      'Shared Discussion UUID %s does not resolve to a discussion node.',
      $discussion_uuid,
    );
    continue;
  }

  $group_titles = [];
  foreach ($records as $record) {
    $activity_uuid = $record['activity_uuid'] ?? '(unknown)';
    $title = $record['discussion_title'] ?? NULL;
    if (!is_string($title) || trim($title) === '') {
      $errors[] = sprintf(
        'Activity UUID %s needs a nonblank discussion_title before repair.',
        $activity_uuid,
      );
      continue 2;
    }
    $group_titles[] = $title;
  }
  if (count(array_unique($group_titles)) === 1) {
    // Equal explicit titles indicate that this sharing is intentional. The
    // normal importer can populate the fields or update the shared node.
    continue;
  }
  if (count(array_unique($group_titles)) !== count($group_titles)) {
    $errors[] = sprintf(
      'Discussion node %d has a mixture of duplicate and unique requested ' .
      'titles. Use all unique titles to split it, or one common title to keep ' .
      'it shared.',
      $discussion->id(),
    );
    continue;
  }

  $exported_node_revision = $records[0]['linked_discussion']['revision_id'] ?? NULL;
  if (!is_int($exported_node_revision)
    && !(is_string($exported_node_revision) && ctype_digit($exported_node_revision))) {
    $errors[] = sprintf('Discussion node %d has an invalid exported revision.', $discussion->id());
    continue;
  }
  if ((int) $discussion->getRevisionId() !== (int) $exported_node_revision
    && !$allow_revision_mismatch) {
    $errors[] = sprintf(
      'Discussion node %d revision differs: export %d, target %d.',
      $discussion->id(),
      (int) $exported_node_revision,
      (int) $discussion->getRevisionId(),
    );
    continue;
  }

  $activities = [];
  foreach ($records as $record) {
    $activity_uuid = $record['activity_uuid'] ?? NULL;
    $title = $record['discussion_title'] ?? NULL;
    $exported_activity_revision = $record['activity_revision_id'] ?? NULL;
    $prompt_body = $record['prompt_body'] ?? NULL;

    if (!is_string($activity_uuid) || $activity_uuid === '') {
      $errors[] = sprintf('A record for discussion %d has no activity UUID.', $discussion->id());
      continue 2;
    }
    if ((!is_int($exported_activity_revision)
      && !(is_string($exported_activity_revision)
        && ctype_digit($exported_activity_revision)))
      || !is_array($prompt_body)
      || !is_string($prompt_body['value'] ?? NULL)
      || !is_string($prompt_body['format'] ?? NULL)) {
      $errors[] = sprintf('Activity UUID %s has invalid export metadata.', $activity_uuid);
      continue 2;
    }

    $activity = $load_by_uuid($activity_storage, $activity_uuid);
    if (!$activity instanceof \Drupal\lms\Entity\ActivityInterface
      || $activity->bundle() !== 'discussion_prompt') {
      $errors[] = sprintf(
        'Activity UUID %s does not resolve to a discussion prompt.',
        $activity_uuid,
      );
      continue 2;
    }
    if ((int) $activity->get('field_discussion_node')->target_id
      !== (int) $discussion->id()) {
      $errors[] = sprintf(
        'Activity %d is no longer linked to discussion node %d.',
        $activity->id(),
        $discussion->id(),
      );
      continue 2;
    }
    if ((int) $activity->getRevisionId() !== (int) $exported_activity_revision
      && !$allow_revision_mismatch) {
      $errors[] = sprintf(
        'Activity %d revision differs: export %d, target %d.',
        $activity->id(),
        (int) $exported_activity_revision,
        (int) $activity->getRevisionId(),
      );
      continue 2;
    }

    $activities[] = [
      'entity' => $activity,
      'title' => $title,
      'prompt_body' => [
        'value' => $prompt_body['value'],
        'format' => $prompt_body['format'],
      ],
    ];
  }

  // Ensure the export includes every current activity reference to this node.
  $current_reference_ids = $activity_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'discussion_prompt')
    ->condition('field_discussion_node.target_id', $discussion->id())
    ->execute();
  $planned_ids = array_map(
    static fn (array $item): int => (int) $item['entity']->id(),
    $activities,
  );
  sort($current_reference_ids);
  sort($planned_ids);
  if (array_map('intval', $current_reference_ids) !== $planned_ids) {
    $errors[] = sprintf(
      'Discussion node %d has current activity references missing from the export.',
      $discussion->id(),
    );
    continue;
  }

  $comment_count = (int) $comment_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('entity_type', 'node')
    ->condition('entity_id', $discussion->id())
    ->count()
    ->execute();

  $keeper_index = NULL;
  foreach ($activities as $index => $item) {
    if ($item['title'] === (string) $discussion->label()) {
      $keeper_index = $index;
      break;
    }
  }
  if ($keeper_index === NULL && $comment_count > 0) {
    $errors[] = sprintf(
      'Discussion node %d has %d comment(s), but no requested title matches ' .
      'its current title "%s". Choose which activity retains it manually.',
      $discussion->id(),
      $comment_count,
      $discussion->label(),
    );
    continue;
  }
  $keeper_index ??= 0;

  $plans[] = [
    'discussion' => $discussion,
    'activities' => $activities,
    'keeper_index' => $keeper_index,
    'comment_count' => $comment_count,
  ];
}

if ($errors !== []) {
  foreach ($errors as $error) {
    print "ERROR: $error\n";
  }
  throw new \RuntimeException(sprintf(
    'Repair aborted: %d validation error(s); nothing was saved.',
    count($errors),
  ));
}

foreach ($plans as $plan) {
  $discussion = $plan['discussion'];
  $keeper = $plan['activities'][$plan['keeper_index']];
  printf(
    "%s Node %d: activity %d keeps the node as \"%s\"; " .
    "%d new node(s); %d existing comment(s) stay on node %d.\n",
    $apply ? 'APPLY' : 'DRY RUN',
    $discussion->id(),
    $keeper['entity']->id(),
    $keeper['title'],
    count($plan['activities']) - 1,
    $plan['comment_count'],
    $discussion->id(),
  );
  foreach ($plan['activities'] as $index => $item) {
    if ($index !== $plan['keeper_index']) {
      printf(
        "  %s Activity %d: create and link \"%s\".\n",
        $apply ? 'APPLY' : 'DRY RUN',
        $item['entity']->id(),
        $item['title'],
      );
    }
  }
}

if (!$apply) {
  printf(
    "Dry run complete: %d shared node group(s), %d new node(s) planned. " .
    "Re-run with -- --apply --uid=USER_ID to repair.\n",
    count($plans),
    array_sum(array_map(
      static fn (array $plan): int => count($plan['activities']) - 1,
      $plans,
    )),
  );
  return;
}

$created = 0;
foreach ($plans as $plan) {
  /** @var \Drupal\node\NodeInterface $discussion */
  $discussion = $plan['discussion'];
  $keeper = $plan['activities'][$plan['keeper_index']];

  $title_changed = (string) $discussion->label() !== $keeper['title'];
  if ($title_changed) {
    $discussion->setTitle($keeper['title']);
  }
  $current_body = $discussion->get('body')->first()?->getValue() ?? [];
  $body_changed = (string) ($current_body['value'] ?? '')
      !== $keeper['prompt_body']['value']
    || (string) ($current_body['format'] ?? '')
      !== $keeper['prompt_body']['format'];
  if ($body_changed) {
    $discussion->set('body', $keeper['prompt_body']);
  }
  if ($title_changed || $body_changed) {
    $discussion->setNewRevision(TRUE);
    $discussion->setRevisionUserId((int) $revision_uid);
    $discussion->setRevisionLogMessage(sprintf(
      'Split shared discussion prompts using %s.',
      basename($input_path),
    ));
    $discussion->save();
  }

  foreach ($plan['activities'] as $index => $item) {
    /** @var \Drupal\lms\Entity\ActivityInterface $activity */
    $activity = $item['entity'];
    $linked_discussion = $discussion;

    if ($index !== $plan['keeper_index']) {
      $linked_discussion = $node_storage->create([
        'type' => 'discussion',
        'title' => $item['title'],
        'uid' => $activity->getOwnerId(),
        'status' => $discussion->isPublished(),
        'langcode' => $activity->language()->getId(),
        'body' => $item['prompt_body'],
        'comment_discussion' => ['status' => 2],
      ]);
      \assert($linked_discussion instanceof \Drupal\node\NodeInterface);
      $linked_discussion->setRevisionUserId((int) $revision_uid);
      $linked_discussion->setRevisionLogMessage(sprintf(
        'Created while splitting shared discussion prompts using %s.',
        basename($input_path),
      ));
      $linked_discussion->save();
      $created++;
    }

    $activity->set('field_discussion_title', $item['title']);
    $activity->set('field_discussion_node', $linked_discussion);
    $activity->setNewRevision(TRUE);
    $activity->setRevisionUserId((int) $revision_uid);
    $activity->setRevisionLogMessage(sprintf(
      'Repaired shared discussion reference using %s.',
      basename($input_path),
    ));
    $activity->save();
  }
}

printf(
  "Repaired %d shared node group(s) and created %d Discussion node(s). " .
  "Re-export before running the normal importer.\n",
  count($plans),
  $created,
);
