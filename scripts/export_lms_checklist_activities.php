<?php

declare(strict_types=1);

/**
 * @file
 * Exports LMS checklist activities to an editable JSON review file.
 *
 * Usage:
 *   drush php:script scripts/export_lms_checklist_activities.php
 *
 * Set CHECKLIST_EXPORT_PATH to choose a different output file. For example:
 *   CHECKLIST_EXPORT_PATH=/tmp/checklists.json \
 *     drush php:script scripts/export_lms_checklist_activities.php
 *
 * This script is export-only. It does not change Drupal content or config.
 */

$output_path = getenv('CHECKLIST_EXPORT_PATH') ?: '/tmp/lms-checklist-activities.json';

if (file_exists($output_path)) {
  throw new \RuntimeException(sprintf(
    'Refusing to overwrite existing export: %s. Move it aside or choose CHECKLIST_EXPORT_PATH.',
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

$activity_storage = \Drupal::entityTypeManager()->getStorage('lms_activity');
$lesson_storage = \Drupal::entityTypeManager()->getStorage('lms_lesson');

$activity_ids = $activity_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'checklist')
  ->sort('id', 'ASC')
  ->execute();

/** @var \Drupal\lms\Entity\ActivityInterface[] $activities */
$activities = $activity_storage->loadMultiple($activity_ids);

// Build read-only lesson context without altering lesson references or order.
$usage = [];
$lesson_ids = $lesson_storage->getQuery()
  ->accessCheck(FALSE)
  ->sort('id', 'ASC')
  ->execute();

/** @var \Drupal\lms\Entity\LessonInterface[] $lessons */
$lessons = $lesson_storage->loadMultiple($lesson_ids);
foreach ($lessons as $lesson) {
  foreach ($lesson->get('activities') as $delta => $reference) {
    $activity_id = (int) $reference->target_id;
    if (!isset($activities[$activity_id])) {
      continue;
    }

    $usage[$activity_id][] = [
      'lesson_id' => (int) $lesson->id(),
      'lesson_uuid' => $lesson->uuid(),
      'lesson_name' => $lesson->label(),
      'activity_delta' => (int) $delta,
      'reference_data' => $reference->get('data')->getValue(),
    ];
  }
}

$records = [];
foreach ($activities as $activity) {
  $body = NULL;
  if ($activity->hasField('field_checklist_body')) {
    $body_values = $activity->get('field_checklist_body')->getValue();
    if ($body_values !== []) {
      $body = [
        'value' => (string) ($body_values[0]['value'] ?? ''),
        'format' => (string) ($body_values[0]['format'] ?? ''),
      ];
    }
  }

  $records[] = [
    'id' => (int) $activity->id(),
    'uuid' => $activity->uuid(),
    'revision_id' => (int) $activity->getRevisionId(),
    'bundle' => $activity->bundle(),
    'name' => (string) $activity->label(),
    'status' => (bool) $activity->isPublished(),
    'langcode' => $activity->language()->getId(),
    'body' => $body,
    'used_in' => $usage[(int) $activity->id()] ?? [],
  ];
}

$export = [
  'export_type' => 'lms_checklist_activities',
  'schema_version' => 1,
  'generated_at' => gmdate('c'),
  'edit_instructions' => 'For a future import, edit only name and body.value/body.format. Do not alter id, uuid, revision_id, bundle, status, langcode, or used_in.',
  'activities' => $records,
];

$json = json_encode(
  $export,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
);

if (file_put_contents($output_path, $json . PHP_EOL) === FALSE) {
  throw new \RuntimeException(sprintf('Unable to write export: %s', $output_path));
}

printf("Exported %d checklist activity record(s) to %s\n", count($records), $output_path);
