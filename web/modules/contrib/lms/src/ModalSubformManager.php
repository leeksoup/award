<?php

declare(strict_types=1);

namespace Drupal\lms;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\lms\Attribute\ModalSubform;

/**
 * Activity-Answer Plugin Manager.
 */
final class ModalSubformManager extends DefaultPluginManager {

  /**
   * {@inheritdoc}
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/ModalSubform',
      $namespaces,
      $module_handler,
      'Drupal\lms\Plugin\ModalSubformInterface',
      ModalSubform::class,
    );
    $this->alterInfo('modal_subform_info');
    $this->setCacheBackend($cache_backend, 'modal_subform');
  }

}
