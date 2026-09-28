<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

/**
 * Checks VIP access against the configured LMS Course and Class memberships.
 */
final class VipGroupAccessChecker {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns whether an account belongs to either configured VIP Hub group.
   */
  public function hasAccess(int $uid): bool {
    if ($uid <= 0) {
      return FALSE;
    }

    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account instanceof UserInterface) {
      return FALSE;
    }

    $config = $this->configFactory->get('commerce_lms_entitlements.vip');
    $group_ids = array_unique(array_filter([
      (int) $config->get('hub_course_id'),
      (int) $config->get('hub_class_id'),
    ]));

    $group_storage = $this->entityTypeManager->getStorage('group');
    foreach ($group_ids as $group_id) {
      $group = $group_storage->load($group_id);
      if ($group instanceof GroupInterface && $group->getMember($account) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
