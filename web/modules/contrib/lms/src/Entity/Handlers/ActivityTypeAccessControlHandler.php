<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\entity\BundleEntityAccessControlHandler;

/**
 * Access controller for the Activity entity.
 *
 * @see \Drupal\lms\Entity\Activity.
 */
final class ActivityTypeAccessControlHandler extends BundleEntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    // Allow view label access if any activity permission is granted.
    if ($operation === 'view label') {
      return AccessResult::allowedIfHasPermissions($account, [
        'administer lms',
        'create lms_activity entities',
        'use all lms_activity entities',
        'dit lms_activity entities',
      ], 'OR');
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
