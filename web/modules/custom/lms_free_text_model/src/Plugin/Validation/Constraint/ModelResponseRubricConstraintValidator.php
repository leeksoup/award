<?php

declare(strict_types=1);

namespace Drupal\lms_free_text_model\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\lms_free_text_model\RubricEvaluator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Rejects invalid author patterns without accepting executable PHP.
 */
final class ModelResponseRubricConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof FieldItemInterface || $value->isEmpty()) {
      return;
    }
    $error = (new RubricEvaluator())->error($value->getValue());
    if ($error !== NULL) {
      $this->context->buildViolation($constraint->invalid)
        ->setParameter('@error', $error)
        ->atPath('pattern')
        ->addViolation();
    }
  }

}
