<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\lms\DataIntegrityChecker;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Form\DataIntegrityWarningTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for reverting a revision.
 */
final class LmsEntityRevisionRevertForm extends RevisionRevertForm {

  use DataIntegrityWarningTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return parent::create($container)
      ->setDataIntegrityChecker($container->get(DataIntegrityChecker::class));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    // Reverting a revision creates a new copy so we don't have to worry about
    // data integrity issues if we use revisions on statuses.
    if ($this->integrityChecker->revisionsUsed()) {
      return $form;
    }

    $parent_entities = [];

    if ($this->revision->getEntityTypeId() === 'lms_lesson') {
      \assert($this->revision instanceof LessonInterface);
      $parent_entities = $this->integrityChecker->checkLessonProgress($this->revision);
    }
    elseif ($this->revision->getEntityTypeId() === 'lms_activity') {
      \assert($this->revision instanceof ActivityInterface);
      $parent_entities = $this->integrityChecker->checkActivityProgress($this->revision);
    }
    if (\count($parent_entities) === 0) {
      return $form;
    }

    $links = [];
    foreach ($parent_entities as $entity) {
      $links[] = $entity->toLink($entity->label(), 'edit-form')->toString();
    }

    $parameters = ['@links' => Markup::create(\implode(', ', $links))];
    if ($this->revision->getEntityTypeId() === 'lms_lesson') {
      $parameters += [
        '@entity_type' => $this->t('lesson'),
      ];
    }
    elseif ($this->revision->getEntityTypeId() === 'lms_activity') {
      $parameters += [
        '@entity_type' => $this->t('activity'),
      ];
    }

    $this->addDataWarning($form, $this->formatPlural(
      \count($links),
      'This @entity_type has already been started by certain students in the following course: @links. Reverting this revision will cause their current progress to become inconsistent. If you proceed, you must manually reset the course progress for all students, which will permanently delete all their answers and progress.',
      'This @entity_type has already been started by certain students in the following courses: @links. Reverting this revision will cause their current progress to become inconsistent. If you proceed, you must manually reset the course progress for all students, which will permanently delete all their answers and progress.',
      $parameters
    ));

    return $form;
  }

}
