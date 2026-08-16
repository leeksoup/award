<?php

declare(strict_types=1);

namespace Drupal\lms;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\lms\Attribute\ActivityAnswer;

/**
 * Activity-Answer Plugin Manager.
 */
class ActivityAnswerManager extends DefaultPluginManager {

  /**
   * {@inheritdoc}
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/ActivityAnswer',
      $namespaces,
      $module_handler,
      'Drupal\lms\Plugin\ActivityAnswerInterface',
      ActivityAnswer::class,
    );
    $this->alterInfo('activity_answer_info');
    $this->setCacheBackend($cache_backend, 'activity_answer');
  }

}
