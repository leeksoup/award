<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Access\GroupAccessResult;
use Drupal\lms\Entity\AnswerInterface;

/**
 * Access controller for the Answer entity.
 *
 * @see \Drupal\lms\Entity\Answer.
 */
final class AnswerAccessControlHandler extends EntityAccessControlHandler {

  /**
   * Checks access for answer entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The answer entity.
   * @param string $operation
   *   The operation related to the entity (e.g., 'view', 'edit', 'delete').
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result object to grant or deny access to the entity.
   */
  public function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    \assert($entity instanceof AnswerInterface);
    if ($operation === 'see_details') {
      // Allow access if the answer belongs to the current user.
      // @todo Use a strict comparison when we're 100% sure
      // AccountInterface::id actually returns int.
      if ($account->id() == $entity->getUserId()) {
        return AccessResult::allowed()->addCacheableDependency($entity);
      }

      // Check if the account has the relevant group permission.
      $course = $entity->getLessonStatus()->getCourseStatus()->getCourse();
      return GroupAccessResult::allowedIfHasGroupPermissions($course, $account, ['grade students']);
    }
    return AccessResult::allowedIfHasPermission($account, 'administer lms');
  }

  /**
   * Checks access for creating an answer entity.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param array $context
   *   The context related to the answer entity.
   * @param string|null $entity_bundle
   *   The answer entity bundle (optional).
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result object to grant or deny creating the answer entity.
   */
  public function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    // We create answers only as a result of answer form logic.
    return AccessResult::forbidden();
  }

}
