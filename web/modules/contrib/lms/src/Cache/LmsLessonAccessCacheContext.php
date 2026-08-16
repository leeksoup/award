<?php

declare(strict_types=1);

namespace Drupal\lms\Cache;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the LMS lesson access cache context service.
 *
 * Cache context ID: 'user.lms_lesson_access'
 *
 * @see \Drupal\lms\Hook\LmsQueryHooks::lmsLessonQueryAccessAlter()
 */
class LmsLessonAccessCacheContext extends LmsEntityAccessCacheContextBase {

  /**
   * {@inheritdoc}
   */
  public static function getLabel(): TranslatableMarkup {
    return t('LMS lesson access grants');
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityTypeId(): string {
    return 'lms_lesson';
  }

}
