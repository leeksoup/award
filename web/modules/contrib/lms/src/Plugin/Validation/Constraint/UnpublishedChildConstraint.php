<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks if a published entity has unpublished parents.
 */
#[Constraint(
  id: 'UnpublishedChildConstraint',
  label: new TranslatableMarkup('Published entity cannot reference an unpublished child', [], ['context' => 'Validation'])
)]
final class UnpublishedChildConstraint extends SymfonyConstraint {

  /**
   * Violation message.
   */
  public string $message = 'The "@label" @referenced_type cannot be referenced. Either publish it first or unpublish the parent (this) @type.';

}
