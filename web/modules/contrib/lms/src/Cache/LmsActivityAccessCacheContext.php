<?php

declare(strict_types=1);

namespace Drupal\lms\Cache;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the LMS activity access cache context service.
 *
 * Cache context ID: 'user.lms_activity_access'
 *
 * @see \Drupal\lms\Hook\LmsQueryHooks::lmsActivityQueryAccessAlter()
 */
class LmsActivityAccessCacheContext extends LmsEntityAccessCacheContextBase {

  /**
   * {@inheritdoc}
   */
  public static function getLabel(): TranslatableMarkup {
    return t('LMS activity access grants');
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityTypeId(): string {
    return 'lms_activity';
  }

}
