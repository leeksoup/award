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
 * Provides a form for deleting a revision.
 *
 * @internal
 */
final class LmsEntityRevisionDeleteForm extends RevisionDeleteForm {

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

    // If we don't use revisions, all statuses point to the current revision
    // and that's not what we're deleting here.
    if (!$this->integrityChecker->revisionsUsed()) {
      return $form;
    }

    $parent_entities = [];
    if ($this->revision->getEntityTypeId() === 'lms_lesson') {
      \assert($this->revision instanceof LessonInterface);
      $parent_entities = $this->integrityChecker->checkLessonProgress($this->revision, TRUE);
    }
    elseif ($this->revision->getEntityTypeId() === 'lms_activity') {
      \assert($this->revision instanceof ActivityInterface);
      $parent_entities = $this->integrityChecker->checkActivityProgress($this->revision, TRUE);
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
      'This @entity_type has already been started by certain students in the following course: @links. Deleting this revision may cause unexpected behavior for students.',
      'This @entity_type has already been started by certain students in the following courses: @links. Deleting this revision may cause unexpected behavior for students.',
      $parameters
    ));

    return $form;
  }

}
