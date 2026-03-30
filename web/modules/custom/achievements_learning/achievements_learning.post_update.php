<?php

/**
 * @file
 * Post update hooks for the Achievements Learning module.
 */

/**
 * Installs missing achievement entity defaults shipped by this module.
 */
function achievements_learning_post_update_install_achievement_entity_defaults(&$sandbox = NULL) {
  \Drupal::service('config.installer')->installDefaultConfig('module', 'achievements_learning');
}

/**
 * Migrates achievement storage keys to fit the achievements table schema.
 */
function achievements_learning_post_update_shorten_achievement_storage_keys(&$sandbox = NULL) {
  $mapping = [
    'achievements_learning:lesson_count' => 'al:lesson_count',
    'achievements_learning:course_count' => 'al:course_count',
    'achievements_learning:forum_topic_count' => 'al:topic_count',
    'achievements_learning:forum_reply_count' => 'al:reply_count',
  ];

  $storage = \Drupal::entityTypeManager()->getStorage('achievement_entity');
  /** @var \Drupal\achievements\Entity\AchievementEntityInterface[] $achievements */
  $achievements = $storage->loadMultiple();
  foreach ($achievements as $achievement) {
    $source = $achievement->getStorage();
    if (!isset($mapping[$source])) {
      continue;
    }

    $achievement->set('storage', $mapping[$source]);
    $achievement->save();
  }
}
