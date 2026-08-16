<?php

/**
 * @file
 * Course views custom implementation.
 */

declare(strict_types=1);

/**
 * Implements hook_views_data_alter().
 */
function lms_views_data_alter(array &$data): void {
  \Drupal::service('lms.hook.other')->viewsDataAlter($data);
}
