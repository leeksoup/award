<?php

/**
 * @file
 * Course views custom implementation.
 */

declare(strict_types=1);

use Drupal\lms_classes\Hook\LmsClassesViewsHooks;

/**
 * Implements hook_views_data_alter().
 */
function lms_classes_views_data_alter(array &$data): void {
  \Drupal::service(LmsClassesViewsHooks::class)->viewsDataAlter($data);
}
