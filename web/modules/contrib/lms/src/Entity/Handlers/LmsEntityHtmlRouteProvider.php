<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;

/**
 * HTML route provider for editable Drupal LMS entities.
 */
final class LmsEntityHtmlRouteProvider extends DefaultHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  protected function getCanonicalRoute(EntityTypeInterface $entity_type) {
    // Edit form is the canonical route for this entity type.
    // We need both for other functionality to work as expected.
    return parent::getEditFormRoute($entity_type);
  }

}
