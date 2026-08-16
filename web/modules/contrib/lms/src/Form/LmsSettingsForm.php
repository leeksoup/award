<?php

declare(strict_types=1);

namespace Drupal\lms\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * From builder class for general LMS settings.
 */
final class LmsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lms_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['lms.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['allow_to_enter_ungraded'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow to enter ungraded courses'),
      '#description' => $this->t('This allows students to reenter finished courses that still need manual grading by a teacher and make changes to their answers. NOTE: If changed on a site with existing courses that are already in progress, caches need to be cleared.'),
      '#config_target' => 'lms.settings:allow_to_enter_ungraded',
    ];

    $form['use_revisions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use revisions'),
      '#description' => $this->t('When this is enabled and the current user starts a course, a lesson or an activity, the currently published revision is locked for the student and updates to those entities while the course is in progress do not affect the student experience in any way.<br />NOTE: cache clear is advised if changing this setting on a production site with courses in progress.<br />NOTE: course creators / editors are not affected and always see the current revisions.'),
      '#config_target' => 'lms.settings:use_revisions',
    ];

    $performance_url = Url::fromRoute('system.performance_settings')->toString();
    $form['show_editor_reset_button'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('"Reset for updates" button for course editors'),
      '#description' => $this->t('Display a button in the course navigation block that gives course editors a 1-click reset of their course progress, so they can view the latest revisions. Not visible to students. NOTE: A <a href=":cache_url">cache clear</a> is required if changing this setting.', [':cache_url' => $performance_url]),
      '#config_target' => 'lms.settings:show_editor_reset_button',
    ];

    return parent::buildForm($form, $form_state);
  }

}
