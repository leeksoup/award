<?php

namespace Drupal\anu_lms\StreamWrapper;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\StreamWrapper\LocalReadOnlyStream;

/**
 * Provides anu-lms:// files stream wrapper.
 */
class AnuLmsReadonlyStreamWrapper extends LocalReadOnlyStream {

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return t('Path to Anu LMS module folder (readonly)');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return t('Provides path to Anu LMS module folder to dynamically load paragraphs images for Paragraphs Browser.');
  }

  /**
   * {@inheritdoc}
   */
  public function getDirectoryPath() {
    return \Drupal::service('extension.list.module')->getPath('anu_lms');
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    return $GLOBALS['base_url'] . '/' . $this->getDirectoryPath() . '/' . UrlHelper::encodePath($this->getTarget());
  }

}
