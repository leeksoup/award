<?php

declare(strict_types=1);

namespace Drupal\lms\Config;

use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Service to allow lazy loading of dependencies used in certain cases.
 */
final class PluginConfigInstaller {

  /**
   * The constructor.
   */
  public function __construct(
    #[AutowireServiceClosure(ModuleExtensionList::class)]
    protected \Closure $moduleList,
    #[AutowireServiceClosure(ConfigInstallerInterface::class)]
    protected \Closure $configInstaller,
  ) {}

  /**
   * Installs additional plugin config.
   */
  public function install(array $definition, string $activity_type_id): void {
    $config_path = ($this->moduleList)()->getPath($definition['provider']);
    $config_path .= '/config/activity_answer/' . $definition['id'];

    // Nothing to install for this plugin.
    if (!\is_dir($config_path)) {
      return;
    }
    $storage = new PluginStorage($config_path, $activity_type_id);
    ($this->configInstaller)()->installOptionalConfig($storage);
  }

}
