<?php

declare(strict_types=1);

namespace Drupal\lms\Cache;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\UserCacheContextBase;
use Drupal\lms\Entity\Handlers\LmsEntityAccessControlHandlerBase;

/**
 * Base cache context for LMS entity access grants.
 *
 * Returns '' for users who can see all entities of the type, or
 * '{uid}' for users restricted to their own entities — mirroring the
 * query-alter logic in LmsQueryHooks.
 */
abstract class LmsEntityAccessCacheContextBase extends UserCacheContextBase {

  /**
   * Returns the entity type ID this context applies to.
   */
  abstract protected function getEntityTypeId(): string;

  /**
   * {@inheritdoc}
   */
  public function getContext(): string {
    $entity_type_id = $this->getEntityTypeId();

    if (
      $this->user->hasPermission('administer lms') ||
      $this->user->hasPermission(LmsEntityAccessControlHandlerBase::getPermission('edit', $entity_type_id)) ||
      $this->user->hasPermission(LmsEntityAccessControlHandlerBase::getPermission('use all', $entity_type_id))
    ) {
      return '';
    }

    return (string) $this->user->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata(): CacheableMetadata {
    // The context is fully encoded in the string; no additional tags needed.
    return new CacheableMetadata();
  }

}
