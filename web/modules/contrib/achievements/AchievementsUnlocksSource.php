<?php

/**
 * @file
 * Contains \Drupal\achievements\Plugin\migrate\source.
 */

namespace Drupal\achievements\Plugin\migrate\source;

use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * Drupal 7 achievement_unlocks source from database.
 *
 * @MigrateSource(
 *   id = "d7_achievement_unlocks_source",
 *   source_module = "achievements"
 * )
 */
class AchievementsUnlocksSource extends DrupalSqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('achievement_unlocks', 'a');

    $query->fields('a', [
      'achievement_id',
      'rank',
      'uid',
      'timestamp',
      'seen',
    ]);

    $query->orderBy('timestamp');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'achievement_id' => $this->t('The achievement ID.'),
      'rank' => $this->t('The rank.'),
      'uid' => $this->t('The ID of the user.'),
      'timestamp' => $this->t('The unlock timestamp.'),
      'seen' => $this->t('Indicates whether the user has seen the notification.'),
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
