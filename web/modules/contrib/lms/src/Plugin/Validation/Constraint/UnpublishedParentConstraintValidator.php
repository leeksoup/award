<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Entity\ActivityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Constraint;

/**
 * Checks if an unpublished entity has published parents.
 */
final class UnpublishedParentConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static($container->get(EntityTypeManagerInterface::class));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $items, Constraint $constraint): void {
    // We don't have to worry if this entity is published.
    if ((bool) $items->value) {
      return;
    }

    /** @var \Drupal\Core\Entity\EntityInterface */
    $entity = $items->getEntity();

    // New entities cannot be referenced as they don't have an ID yet.
    if ($entity->isNew()) {
      return;
    }

    if ($entity instanceof LessonInterface) {
      $queried_entity_type = 'group';
    }
    elseif ($entity instanceof ActivityInterface) {
      $queried_entity_type = 'lms_lesson';
    }
    else {
      throw new \InvalidArgumentException('Trying to validate an unsupported entity type.');
    }

    $entity_storage = $this->entityTypeManager->getStorage($queried_entity_type);
    $query = $entity_storage->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('status', TRUE);
    if ($queried_entity_type === 'group') {
      $query->condition('type', 'lms_course');
      $query->condition(Course::LESSONS, $entity->id());
    }
    else {
      $query->condition(LessonInterface::ACTIVITIES, $entity->id());
    }
    $results = $query->execute();
    if (\count($results) === 0) {
      return;
    }

    $labels = [];
    foreach ($results as $entity_id) {
      $entity = $entity_storage->load($entity_id);
      $labels[] = $entity->label();
    }

    if ($queried_entity_type === 'group') {
      $current_label = $this->t('lesson');
      $parent_label = $this->formatPlural(\count($results), 'course', 'courses');
    }
    else {
      $current_label = $this->t('activity');
      $parent_label = $this->formatPlural(\count($results), 'lesson', 'lessons');
    }

    \assert($constraint instanceof UnpublishedParentConstraint);
    $this->context->addViolation($constraint->message, [
      '@entity_type' => $current_label,
      '@parent_type' => $parent_label,
      '%labels' => \implode(', ', $labels),
    ]);
  }

}
