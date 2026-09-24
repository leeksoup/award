<?php

declare(strict_types=1);

/**
 * @file
 * Exports LMS discussion prompt activities to editable JSON.
 *
 * Usage:
 *   drush php:script scripts/export_lms_discussion_prompt_activities.php
 *   drush php:script scripts/export_lms_discussion_prompt_activities.php \
 *     -- --output=/tmp/discussion-prompts.json
 *
 * DISCUSSION_PROMPT_EXPORT_PATH may also specify the output path. The default
 * is /tmp/lms-discussion-prompt-activities.json.
 *
 * This script does not change Drupal content or configuration.
 */

// Drush removes the script name and exposes arguments after `--` through the
// local $extra variable before including this file. Keep argv as a fallback so
// the parsing remains predictable when the script is executed another way.
$arguments = isset($extra) && is_array($extra)
  ? $extra
  : array_slice($_SERVER['argv'] ?? [], 1);
$output_path = getenv('DISCUSSION_PROMPT_EXPORT_PATH')
  ?: '/tmp/lms-discussion-prompt-activities.json';

foreach ($arguments as $argument) {
  if (str_starts_with($argument, '--output=')) {
    $output_path = substr($argument, strlen('--output='));
  }
}

if ($output_path === '') {
  throw new \RuntimeException('The export output path cannot be empty.');
}
if (file_exists($output_path)) {
  throw new \RuntimeException(sprintf(
    'Refusing to overwrite existing export: %s. Move it aside or select another output path.',
    $output_path,
  ));
}

$output_directory = dirname($output_path);
if (!is_dir($output_directory) || !is_writable($output_directory)) {
  throw new \RuntimeException(sprintf(
    'Output directory does not exist or is not writable: %s',
    $output_directory,
  ));
}

$entity_type_manager = \Drupal::entityTypeManager();
$activity_storage = $entity_type_manager->getStorage('lms_activity');
$lesson_storage = $entity_type_manager->getStorage('lms_lesson');
$course_storage = $entity_type_manager->getStorage('group');

$activity_ids = $activity_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'discussion_prompt')
  ->sort('id', 'ASC')
  ->execute();

/** @var \Drupal\lms\Entity\ActivityInterface[] $activities */
$activities = $activity_storage->loadMultiple($activity_ids);

// Build read-only course context keyed by lesson ID.
$courses_by_lesson = [];
$course_ids = $course_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'lms_course')
  ->sort('id', 'ASC')
  ->execute();

/** @var \Drupal\group\Entity\GroupInterface[] $courses */
$courses = $course_storage->loadMultiple($course_ids);
foreach ($courses as $course) {
  if (!$course->hasField('lessons')) {
    continue;
  }

  foreach ($course->get('lessons') as $course_delta => $lesson_reference) {
    if ($lesson_reference->target_id === NULL) {
      continue;
    }

    $courses_by_lesson[(int) $lesson_reference->target_id][] = [
      'course_id' => (int) $course->id(),
      'course_uuid' => $course->uuid(),
      'course_name' => (string) $course->label(),
      'lesson_delta' => (int) $course_delta,
    ];
  }
}

// Build read-only lesson and course context without changing reference order.
$usage = [];
$lesson_ids = $lesson_storage->getQuery()
  ->accessCheck(FALSE)
  ->sort('id', 'ASC')
  ->execute();

/** @var \Drupal\lms\Entity\LessonInterface[] $lessons */
$lessons = $lesson_storage->loadMultiple($lesson_ids);
foreach ($lessons as $lesson) {
  foreach ($lesson->get('activities') as $activity_delta => $reference) {
    $activity_id = (int) $reference->target_id;
    if (!isset($activities[$activity_id])) {
      continue;
    }

    $usage[$activity_id][] = [
      'lesson_id' => (int) $lesson->id(),
      'lesson_uuid' => $lesson->uuid(),
      'lesson_name' => (string) $lesson->label(),
      'activity_delta' => (int) $activity_delta,
      'reference_data' => $reference->get('data')->getValue(),
      'courses' => $courses_by_lesson[(int) $lesson->id()] ?? [],
    ];
  }
}

$records = [];
$discussion_usage = [];
$discussion_details = [];
foreach ($activities as $activity) {
  if (!$activity->hasField('field_discussion_prompt_body')
    || !$activity->hasField('field_discussion_title')
    || !$activity->hasField('field_discussion_node')) {
    throw new \RuntimeException(sprintf(
      'Discussion prompt activity %d does not have all expected fields.',
      (int) $activity->id(),
    ));
  }

  $body_values = $activity->get('field_discussion_prompt_body')->getValue();
  $body = [
    'value' => (string) ($body_values[0]['value'] ?? ''),
    'format' => (string) ($body_values[0]['format'] ?? ''),
  ];

  $linked_discussion = NULL;
  if (!$activity->get('field_discussion_node')->isEmpty()) {
    $discussion = $activity->get('field_discussion_node')->entity;
    if (!$discussion instanceof \Drupal\node\NodeInterface) {
      throw new \RuntimeException(sprintf(
        'Activity %d has an invalid field_discussion_node reference.',
        (int) $activity->id(),
      ));
    }

    $linked_discussion = [
      'node_id' => (int) $discussion->id(),
      'uuid' => $discussion->uuid(),
      'revision_id' => (int) $discussion->getRevisionId(),
      'bundle' => $discussion->bundle(),
      'title' => (string) $discussion->label(),
    ];
    $discussion_usage[$discussion->uuid()][] = [
      'activity_id' => (int) $activity->id(),
      'activity_uuid' => $activity->uuid(),
      'activity_name' => (string) $activity->label(),
    ];
    $discussion_details[$discussion->uuid()] = [
      'node_id' => (int) $discussion->id(),
      'title' => (string) $discussion->label(),
    ];
  }

  $records[] = [
    'activity_id' => (int) $activity->id(),
    'activity_uuid' => $activity->uuid(),
    'activity_revision_id' => (int) $activity->getRevisionId(),
    'bundle' => $activity->bundle(),
    'name' => (string) $activity->label(),
    'status' => (bool) $activity->isPublished(),
    'langcode' => $activity->language()->getId(),
    'discussion_title' => (string) $activity->get('field_discussion_title')->value,
    'prompt_body' => $body,
    'linked_discussion' => $linked_discussion,
    'used_in' => $usage[(int) $activity->id()] ?? [],
  ];
}

$shared_discussions = [];
foreach ($discussion_usage as $discussion_uuid => $referencing_activities) {
  if (count($referencing_activities) < 2) {
    continue;
  }

  $shared_discussions[] = [
    'discussion_uuid' => $discussion_uuid,
    'node_id' => $discussion_details[$discussion_uuid]['node_id'],
    'title' => $discussion_details[$discussion_uuid]['title'],
    'referencing_activities' => $referencing_activities,
  ];
}

$export = [
  'export_type' => 'lms_discussion_prompt_activities',
  'schema_version' => 1,
  'generated_at' => gmdate('c'),
  'edit_instructions' => implode(' ', [
    'Edit only name, discussion_title, prompt_body.value, prompt_body.format,',
    'and linked_discussion.title.',
    'Usually discussion_title and linked_discussion.title should remain the same.',
    'All IDs, UUIDs, revision IDs, bundle, status, langcode, and used_in',
    'are validation or context values.',
  ]),
  'shared_discussion_warning' => $shared_discussions === []
    ? NULL
    : implode(' ', [
      'Some activities reference the same Discussion node.',
      'Review shared_discussions before importing.',
      'For accidental sharing, give every affected activity a unique, nonblank',
      'discussion_title and run repair_shared_lms_discussion_prompt_nodes.php.',
    ]),
  'shared_discussions' => $shared_discussions,
  'activities' => $records,
];

$json = json_encode(
  $export,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
);

if (file_put_contents($output_path, $json . PHP_EOL) === FALSE) {
  throw new \RuntimeException(sprintf('Unable to write export: %s', $output_path));
}

printf(
  "Exported %d discussion prompt activity record(s) to %s\n",
  count($records),
  $output_path,
);
if ($shared_discussions !== []) {
  printf(
    "WARNING: Found %d Discussion node(s) referenced by multiple activities. " .
    "Review shared_discussions in the export before importing.\n",
    count($shared_discussions),
  );
}
