<?php

declare(strict_types=1);

namespace Drupal\lms_free_text_model\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validates a rubric item's regex and weight on entity validation.
 *
 * @Constraint(
 *   id = "LmsFreeTextModelRubric",
 *   label = @Translation("Model response rubric check", context = "Validation")
 * )
 */
final class ModelResponseRubricConstraint extends Constraint {

  public string $invalid = '@error';

}
