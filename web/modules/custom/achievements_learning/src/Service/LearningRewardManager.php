<?php

namespace Drupal\achievements_learning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Manages reward eligibility, options, and claims.
 */
class LearningRewardManager {

  /**
   * Constructs the reward manager.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Checks whether a reward choice is available.
   */
  public function isRewardChoiceAvailable(int $uid, string $achievementId): bool {
    return !$this->hasClaimedReward($uid, $achievementId);
  }

  /**
   * Returns configured reward choices for an achievement.
   */
  public function getRewardChoices(string $achievementId): array {
    $config = $this->configFactory->get('achievements_learning.settings');
    $reward_rules = $config->get('reward_rules') ?? [];
    $reward_items = $config->get('reward_items') ?? [];

    foreach ($reward_rules as $rule) {
      if (($rule['achievement_id'] ?? NULL) !== $achievementId || empty($rule['enabled'])) {
        continue;
      }
      $reward_group = $rule['reward_group'] ?? '';
      return array_values(array_filter($reward_items, static fn (array $item): bool => !empty($item['active']) && ($item['reward_group'] ?? '') === $reward_group));
    }

    return [];
  }

  /**
   * Records a reward claim.
   */
  public function claimReward(int $uid, string $achievementId, string $rewardId): bool {
    if ($this->hasClaimedReward($uid, $achievementId)) {
      return FALSE;
    }

    $this->database->insert('achievements_learning_reward_claim')
      ->fields([
        'uid' => $uid,
        'achievement_id' => $achievementId,
        'reward_id' => $rewardId,
        'created' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    return TRUE;
  }

  /**
   * Determines whether a reward has already been claimed.
   */
  protected function hasClaimedReward(int $uid, string $achievementId): bool {
    $result = $this->database->select('achievements_learning_reward_claim', 'claim')
      ->fields('claim', ['id'])
      ->condition('uid', $uid)
      ->condition('achievement_id', $achievementId)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return !empty($result);
  }

}
