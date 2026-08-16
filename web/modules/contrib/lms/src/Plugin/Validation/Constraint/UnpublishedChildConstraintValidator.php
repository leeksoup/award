<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Validation\Constraint;

use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\LessonInterface;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Constraint;

/**
 * Checks if an unpublished entity has published parents.
 */
final class UnpublishedChildConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $items, Constraint $constraint): void {
    // We don't have to worry if this entity is not published.
    $entity = $items->getEntity();
    if (!$entity instanceof EntityPublishedInterface) {
      return;
    }
    if (!$entity->isPublished()) {
      return;
    }

    if ($entity instanceof Course) {
      $type = new TranslatableMarkup('course');
    }
    elseif ($entity instanceof LessonInterface) {
      $type = new TranslatableMarkup('lesson');
    }
    else {
      throw new \InvalidArgumentException('Unsupported entity type / bundle.');
    }

    \assert($constraint instanceof UnpublishedChildConstraint);

    foreach ($items as $delta => $item) {
      \assert($item->entity instanceof EntityPublishedInterface);
      if (!$item->entity->isPublished()) {
        $referenced_type = $item->entity instanceof LessonInterface ? new TranslatableMarkup('lesson') : new TranslatableMarkup('activity');
        $this->context
          ->buildViolation($constraint->message)
          ->setParameter('@label', $item->entity->label())
          ->setParameter('@referenced_type', (string) $referenced_type)
          ->setParameter('@type', (string) $type)
          ->atPath((string) $delta)
          ->addViolation();
      }
    }
  }

}
