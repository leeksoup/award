<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\lms\Form\DataIntegrityWarningTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lesson delete form.
 */
final class LessonDeleteForm extends ContentEntityDeleteForm {

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

    $courses = $this->integrityChecker->getLessonUsage($this->getEntity()->id());
    if (\count($courses) !== 0) {
      $links = [];
      foreach ($courses as $course) {
        $links[] = $course->toLink($course->label(), 'edit-form')->toString();
      }
      $this->addDataWarning($form, $this->formatPlural(
        \count($links),
        'This lesson is used in the following course: @courses. Please remove the usage before deleting it.',
        'This lesson is used in following courses: @courses. Please remove the usage before deleting it.',
        ['@courses' => Markup::create(\implode(', ', $links))]
      ));
      $form['actions']['submit']['#disabled'] = TRUE;
      unset($form['description']);
    }

    return $form;
  }

}
