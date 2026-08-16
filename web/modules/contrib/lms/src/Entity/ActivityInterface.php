<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for defining Activity entities.
 */
interface ActivityInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

}
