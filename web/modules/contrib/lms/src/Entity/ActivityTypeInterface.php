<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for the activity type entity.
 */
interface ActivityTypeInterface extends ConfigEntityInterface {

  /**
   * Get name of the activity type.
   */
  public function getName(): string;

  /**
   * Get description of the activity type.
   */
  public function getDescription(): string;

  /**
   * Set description of the activity type.
   */
  public function setDescription(string $description): void;

  /**
   * Get activity - answer plugin ID for this activity type.
   */
  public function getPluginId(): string;

  /**
   * Get plugin configuration.
   */
  public function getPluginConfiguration(): array;

  /**
   * Returns the default maximum score of the activity type entity.
   */
  public function getDefaultMaxScore(): int;

}
