<?php

declare(strict_types=1);

namespace Drupal\lms\Config;

use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

/**
 * Service to allow lazy loading of dependencies used in certain cases.
 */
final class PluginConfigInstaller {

  /**
   * The constructor.
   */
  public function __construct(
    #[AutowireCallable(service: 'extension.list.module', method: 'getPath', lazy: TRUE)]
    protected \Closure $getModulePath,
    #[AutowireCallable(service: 'config.installer', method: 'installOptionalConfig', lazy: TRUE)]
    protected \Closure $installOptionalConfig,
  ) {}

  /**
   * Installs additional plugin config.
   */
  public function install(array $definition, string $activity_type_id): void {
    $config_path = ($this->getModulePath)($definition['provider']);
    $config_path .= '/config/activity_answer/' . $definition['id'];

    // Nothing to install for this plugin.
    if (!\is_dir($config_path)) {
      return;
    }
    $storage = new PluginStorage($config_path, $activity_type_id);
    ($this->installOptionalConfig)($storage);
  }

}
