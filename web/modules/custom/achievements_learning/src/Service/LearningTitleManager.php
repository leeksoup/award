<?php

namespace Drupal\achievements_learning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;
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
  public function recomputeCurrentTitle(int $uid, mixed $changedAchievement = NULL): void {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account instanceof UserInterface) {
      return;
    }

    $title_field = $this->configFactory->get('achievements_learning.settings')->get('current_title_field') ?: 'field_learning_current_title';
    if (!$account->hasField($title_field)) {
      $this->logger->warning('Configured title field @field does not exist on user @uid.', ['@field' => $title_field, '@uid' => $uid]);
      return;
    }

    $best_title = $this->getBestTitleForUser($uid, $changedAchievement);
    if ((string) $account->get($title_field)->value !== $best_title) {
      $account->set($title_field, $best_title);
      $account->save();
    }
  }

  /**
   * Resolves the highest-priority configured title for a user.
   */
  public function getBestTitleForUser(int $uid, mixed $changedAchievement = NULL): string {
    $title_priority = $this->configFactory->get('achievements_learning.settings')->get('title_priority') ?? [];
    $unlocked_titles = $this->getUnlockedTitlesForUser($uid);

    $achievement_label = '';
    $achievement_id = '';
    if (is_object($changedAchievement)) {
      $achievement_label = method_exists($changedAchievement, 'label') ? (string) $changedAchievement->label() : '';
      $achievement_id = method_exists($changedAchievement, 'id') ? (string) $changedAchievement->id() : '';
    }
    elseif (is_array($changedAchievement)) {
      $achievement_label = (string) ($changedAchievement['title'] ?? $changedAchievement['name'] ?? '');
      $achievement_id = (string) ($changedAchievement['achievement_id'] ?? '');
    }

    if ($achievement_label !== '' && in_array($achievement_label, $title_priority, TRUE) && !in_array($achievement_label, $unlocked_titles, TRUE) && $achievement_id !== '' && achievements_unlocked_already($achievement_id, $uid)) {
      $unlocked_titles[] = $achievement_label;
    }

    foreach ($title_priority as $title) {
      if (in_array($title, $unlocked_titles, TRUE)) {
        return (string) $title;
      }
    }

    return '';
  }

  /**
   * Returns the user's current projected title or computes it if needed.
   */
  public function getCurrentTitleForUser(int $uid): string {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account instanceof UserInterface) {
      return '';
    }

    $title_field = $this->configFactory->get('achievements_learning.settings')->get('current_title_field') ?: 'field_learning_current_title';
    if ($account->hasField($title_field) && !$account->get($title_field)->isEmpty()) {
      return (string) $account->get($title_field)->value;
    }

    return $this->getBestTitleForUser($uid);
  }

  /**
   * Returns the unlocked configured titles for a user.
   *
   * @return string[]
   *   A list of unlocked title strings.
   */
  protected function getUnlockedTitlesForUser(int $uid): array {
    $rules = $this->configFactory->get('achievements_learning.settings')->get('milestone_rules') ?? [];
    $unlocked_titles = [];

    foreach ($rules as $rule) {
      if (empty($rule['enabled']) || empty($rule['title_enabled']) || empty($rule['title']) || empty($rule['achievement_id'])) {
        continue;
      }

      if (achievements_unlocked_already($rule['achievement_id'], $uid)) {
        $unlocked_titles[] = (string) $rule['title'];
      }
    }

    return array_values(array_unique($unlocked_titles));
  }

}
