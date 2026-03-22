<?php

namespace Drupal\achievements_learning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Synchronizes the user's current learning title projection field.
 */
class LearningTitleManager {

  /**
   * Constructs the title manager.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Recomputes the current title for a user.
   */
  public function recomputeCurrentTitle(int $uid, array $changedAchievement = []): void {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account) {
      return;
    }

    $title_field = $this->configFactory->get('achievements_learning.settings')->get('current_title_field') ?: 'field_learning_current_title';
    if (!$account->hasField($title_field)) {
      $this->logger->warning('Configured title field @field does not exist on user @uid.', ['@field' => $title_field, '@uid' => $uid]);
      return;
    }

    $best_title = $this->getBestTitleForUser($uid, $changedAchievement);
    $account->set($title_field, $best_title);
    $account->save();
  }

  /**
   * Resolves the highest-priority configured title for a user.
   */
  public function getBestTitleForUser(int $uid, array $changedAchievement = []): string {
    $title_priority = $this->configFactory->get('achievements_learning.settings')->get('title_priority') ?? [];
    $achievement_label = (string) ($changedAchievement['title'] ?? $changedAchievement['name'] ?? '');

    if ($achievement_label !== '' && in_array($achievement_label, $title_priority, TRUE)) {
      return $achievement_label;
    }

    return '';
  }

}
