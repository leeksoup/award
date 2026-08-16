<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Base class for editable LMS entities.
 */
abstract class LmsEntityAccessControlHandlerBase extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if (
      // Allow deleting revisions to administrators only for now.
      $operation !== 'delete revision' &&
      $entity instanceof EntityOwnerInterface &&
      $entity->getOwnerId() === $account->id()
    ) {
      return AccessResult::allowedIfHasPermissions($account, [
        'administer lms',
        static::getPermission('create', $this->entityTypeId),
      ], 'OR')->cachePerUser()->addCacheableDependency($entity);
    }

    return AccessResult::allowedIfHasPermissions($account, [
      'administer lms',
      static::getPermission('edit', $this->entityTypeId),
    ], 'OR');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermissions($account, [
      'administer lms',
      static::getPermission('create', $this->entityTypeId),
    ], 'OR');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($operation !== 'edit') {
      return parent::checkFieldAccess($operation, $field_definition, $account, $items);
    }

    // Only users with the administer nodes permission can edit administrative
    // fields.
    $administrative_fields = ['uid', 'created', 'changed', 'revision_log'];
    if (\in_array($field_definition->getName(), $administrative_fields, TRUE)) {
      return AccessResult::allowedIfHasPermission($account, 'administer lms');
    }

    // No user can change read only fields.
    $read_only_fields = ['revision_timestamp', 'revision_uid'];
    if (\in_array($field_definition->getName(), $read_only_fields, TRUE)) {
      return AccessResult::forbidden();
    }

    return parent::checkFieldAccess($operation, $field_definition, $account, $items);
  }

  /**
   * Check LMS reference field access, used in courses too.
   */
  public static function lmsReferenceFieldEditAccess(string $target_type, AccountInterface $account): AccessResultInterface {
    $result = AccessResult::allowedIfHasPermissions($account, [
      static::getPermission('create', $target_type),
      static::getPermission('use all', $target_type),
      static::getPermission('edit', $target_type),
    ], 'OR');
    if (!$result->isAllowed()) {
      $result = AccessResult::forbidden();
    }

    return $result->addCacheContexts(['user.permissions']);
  }

  /**
   * Get permission by operation type and entity type ID.
   */
  public static function getPermission(string $operation, string $target_type): string {
    return $operation . ' ' . $target_type . ' entities';
  }

}
