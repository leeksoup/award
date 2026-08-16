<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\lms\Form\DataIntegrityWarningTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Activity delete form.
 */
final class ActivityDeleteForm extends ContentEntityDeleteForm {

  use DataIntegrityWarningTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return parent::create($container)->setDataIntegrityChecker($container->get('lms.data_integrity_checker'));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $lessons = $this->integrityChecker->getActivityUsage($this->getEntity()->id());
    if (\count($lessons) !== 0) {
      $links = [];
      foreach ($lessons as $lesson) {
        $links[] = $lesson->toLink($lesson->label(), 'edit-form')->toString();
      }
      $this->addDataWarning($form, $this->formatPlural(
        \count($links),
        'This activity is used in the following lesson: @lessons. Please remove the usage before deleting it.',
        'This activity is used in following lessons: @lessons. Please remove the usage before deleting it.',
        ['@lessons' => Markup::create(\implode(', ', $links))]
      ));
      $form['actions']['submit']['#disabled'] = TRUE;
      unset($form['description']);
    }

    return $form;
  }

}
