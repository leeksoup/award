<?php

declare(strict_types=1);

namespace Drupal\lms\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lms\DataIntegrityChecker;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Form\DataIntegrityWarningTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Simple form alter hooks.
 */
final class LmsFormHooks {

  use StringTranslationTrait;
  use DataIntegrityWarningTrait;

  public function __construct(
    #[Autowire(service: DataIntegrityChecker::class, lazy: TRUE)]
    protected DataIntegrityChecker $integrityChecker,
  ) {}

  #[Hook('form_views_exposed_form_alter')]
  public function formViewsExposedFormAlter(array &$form, FormStateInterface $form_state): void {
    // We don't want filter buttons at the bottom of modals.
    if ($form['#action'] === Url::fromRoute('lms.modal_subform_endpoint')->toString()) {
      $form['actions']['#type'] = 'container';
      $form['actions']['#attributes'] = ['class' => 'modal-form-actions'];
    }
  }

  #[Hook('form_group_form_alter')]
  public function groupFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    \assert($form_object instanceof EntityFormInterface);
    $group = $form_object->getEntity();
    if (!$group instanceof Course) {
      return;
    }
    $in_progress = $this->integrityChecker->checkCourseProgress($group);
    if (!$in_progress) {
      return;
    }

    if ($this->integrityChecker->revisionsUsed()) {
      $form['revision']['#description'] = $this->t('This course has already been started by certain students, it is advised to create a new revision to avoid data inconsistency issues.');
    }
    else {
      $affected_fields = [
        'lessons',
      ];
      foreach ($affected_fields as $field) {
        $this->addDataWarning($form[$field], $this->t('This course has already been started by certain students, changing the field below may cause their current progress to become inconsistent with the new value.'));
      }
    }
  }

}
