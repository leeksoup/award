<?php

/**
 * @file
 * Contains post-update hooks for the LMS Classes module.
 */

declare(strict_types=1);

/**
 * Set initial values for class - course permission mappings.
 */
function lms_classes_post_update_set_permission_mappings(): void {
  $config = \Drupal::configFactory()->getEditable('lms_classes.settings');
  foreach ([
    'create_default_class' => TRUE,
    'class_name_pattern' => '',
    'class_permission_mappings' => [],
    'course_permission_mappings' => [],
  ] as $key => $default_value) {
    if ($config->get($key) === NULL) {
      $config->set($key, $default_value);
    }
  }
  $config->save();
}
