<?php

namespace Drupal\achievements_learning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Tracks eligible forum activity for achievements.
 */
class LearningForumManager {

  /**
   * Constructs the forum manager.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Records a created forum topic.
   */
  public function recordTopicCreated(int $uid, int $nid): void {
    $this->logger->info('Forum topic scaffold recorded for uid @uid and node @nid.', ['@uid' => $uid, '@nid' => $nid]);
  }

  /**
   * Records a created forum reply.
   */
  public function recordReplyCreated(int $uid, int $cid): void {
    $this->logger->info('Forum reply scaffold recorded for uid @uid and comment @cid.', ['@uid' => $uid, '@cid' => $cid]);
  }

  /**
   * Determines if the account is eligible for forum achievements.
   */
  public function isEligibleForumParticipant(?AccountInterface $account): bool {
    if (!$account || !$account->id()) {
      return FALSE;
    }

    $excluded_roles = $this->configFactory->get('achievements_learning.settings')->get('forum_excluded_roles') ?? [];
    return count(array_intersect($account->getRoles(), $excluded_roles)) === 0;
  }

}
