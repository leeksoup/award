<?php

declare(strict_types=1);

namespace Drupal\lms\Config;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\UnsupportedDataTypeConfigException;

/**
 * Service to allow lazy loading of dependencies used in certain cases.
 */
final class PluginStorage extends FileStorage {

  /**
   * Stores original config file names for parent methods.
   */
  private array $originalNames;

  /**
   * The constructor.
   */
  public function __construct(
    string $directory,
    private readonly string $activityTypeId,
  ) {
    parent::__construct($directory);
  }

  /**
   * {@inheritdoc}
   */
  public function read($name) {
    if (!$this->exists($this->originalNames[$name])) {
      return FALSE;
    }

    $filepath = $this->getFilePath($this->originalNames[$name]);

    // Do not use the parent fileCache here: the same template file is shared
    // across multiple bundles, so the substituted content differs per bundle.
    // Reading from disk each time is acceptable for small config install files.
    $raw = \file_get_contents($filepath);
    $data = \strtr($raw, ['_bundle_placeholder_' => $this->activityTypeId]);
    try {
      $data = $this->decode($data);
    }
    catch (InvalidDataTypeException $e) {
      throw new UnsupportedDataTypeConfigException('Invalid data type in config ' . $name . ', found in file ' . $filepath . ': ' . $e->getMessage());
    }

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function listAll($prefix = '') {
    $this->originalNames = [];
    $names = parent::listAll($prefix);
    foreach ($names as &$name) {
      $converted = \strtr($name, ['_bundle_placeholder_' => $this->activityTypeId]);
      $this->originalNames[$converted] = $name;
      $name = $converted;
    }

    return $names;
  }

}
