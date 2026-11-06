<?php

declare(strict_types=1);

namespace Drupal\lms\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lms\DataIntegrityChecker;
use Drupal\lms\Entity\Bundle\CourseInterface;
use Drupal\lms\Form\DataIntegrityWarningTrait;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Simple form alter hooks.
 */
final class LmsFormHooks {

  use StringTranslationTrait;
  use DataIntegrityWarningTrait;

  public function __construct(
    #[AutowireServiceClosure(DataIntegrityChecker::class)]
    protected \Closure $integrityCheckerFactory,
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
    if (!$group instanceof CourseInterface) {
      return;
    }
    $integrity_checker = ($this->integrityCheckerFactory)();
    $in_progress = $integrity_checker->checkCourseProgress($group);
    if (!$in_progress) {
      return;
    }

    if ($integrity_checker->revisionsUsed()) {
      $form['revision']['#description'] = $this->t('Students are currently taking this course. Choose how your updates will affect them by checking or unchecking the "Create new revision" box below.');
    }
    else {
      $affected_fields = [
        'lessons',
      ];
      foreach ($affected_fields as $field) {
        $this->addDataWarning($form[$field], $this->t('Students are currently taking this course. Making structural or scoring changes to the fields below may cause their progress to break. If you need to make changes for new enrollments without affecting current students, you can enable "Use revisions" in the <a href="@general_settings_url">LMS general settings</a>.', ['@general_settings_url' => '/admin/lms/settings/lms']));
      }
    }
  }

}
