<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks if an unpublished entity has published parents.
 */
#[Constraint(
  id: 'UnpublishedParentConstraint',
  label: new TranslatableMarkup('Entity assigned to a published parent must be published', [], ['context' => 'Validation'])
)]
final class UnpublishedParentConstraint extends SymfonyConstraint {

  /**
   * Violation message.
   */
  public string $message = 'This @entity_type cannot be unpublished as it is a part of the following published @parent_type: %labels.';

}
