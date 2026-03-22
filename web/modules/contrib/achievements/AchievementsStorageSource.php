<?php

/**
 * @file
 * Contains \Drupal\achievements\Plugin\migrate\source.
 */

namespace Drupal\achievements\Plugin\migrate\source;

use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * Drupal 7 achievement_storage source from database.
 *
 * @MigrateSource(
 *   id = "d7_achievement_storage_source",
 *   source_module = "achievements"
 * )
 */
class AchievementsStorageSource extends DrupalSqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('achievement_storage', 'a');

    $query->fields('a', [
      'achievement_id',
      'uid',
      'data',
    ]);

    $query->orderBy('uid')->orderBy('achievement_id');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'achievement_id' => $this->t('The achievement ID.'),
      'uid' => $this->t('The ID of the user.'),
      'data' => $this->t('The achievement data.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['achievement_id']['type'] = 'string';
    $ids['uid']['type'] = 'integer';

    return $ids;
  }

}
