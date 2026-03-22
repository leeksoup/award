<?php

/**
 * @file
 * Contains \Drupal\achievements\Plugin\migrate\source.
 */

namespace Drupal\achievements\Plugin\migrate\source;

use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * Drupal 7 achievement_totals source from database.
 *
 * @MigrateSource(
 *   id = "d7_achievement_totals_source",
 *   source_module = "achievements"
 * )
 */
class AchievementsTotalsSource extends DrupalSqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('achievement_totals', 'a');

    $query->fields('a', [
      'uid',
      'points',
      'unlocks',
      'timestamp',
      'achievement_id',
    ]);

    $query->orderBy('timestamp');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'uid' => $this->t('The ID of the user.'),
      'points' => $this->t('The amount of points'),
      'unlocks' => $this->t('The amount of unlocks.'),
      'timestamp' => $this->t('The timestamp of the last change.'),
      'achievement_id' => $this->t('The achievement ID.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['uid']['type'] = 'integer';

    return $ids;
  }

}
