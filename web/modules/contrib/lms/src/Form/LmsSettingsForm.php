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
    $performance_url = Url::fromRoute('system.performance_settings')->toString();
    $form['allow_to_enter_ungraded'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Re-entry to courses awaiting grading'),
      '#description' => $this->t('This allows students to re-enter finished courses that still need manual grading by a teacher. Students may make changes to their answers.<br />NOTE: If changed on a site with existing courses in progress, <a href=":cache_url">caches need to be cleared</a>.', [':cache_url' => $performance_url]),
      '#config_target' => 'lms.settings:allow_to_enter_ungraded',
    ];

    $form['use_revisions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use revisions'),
      '#description' => $this->t('When this is enabled and a user starts a course, the exact current version of the course is locked in for that user. Making a new revision of the course, or any of its lessons and activities, will not affect the experience of current students in any way.<br />NOTE: A <a href=":cache_url">cache clear</a> is advised if changing this setting on a production site with courses in progress.<br />NOTE: course creators and editors will always see the latest revisions of course content, but the course navigation block is not updated unless their course progress is reset.', [':cache_url' => $performance_url]),
      '#config_target' => 'lms.settings:use_revisions',
    ];

    $form['show_editor_reset_button'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('"Reset for updates" button for course editors'),
      '#description' => $this->t('Display a button in the course navigation block that gives course editors a 1-click reset of their course progress, so they can see the latest version. Not visible to students.<br />NOTE: A <a href=":cache_url">cache clear</a> is required for changes to this setting to take effect.', [':cache_url' => $performance_url]),
      '#config_target' => 'lms.settings:show_editor_reset_button',
    ];

    $form['activity_page_title'] = [
      '#type' => 'textfield',
      '#maxlength' => 255,
      '#required' => TRUE,
      '#title' => $this->t('Activity page title pattern'),
      '#description' => $this->t('The title of the page where students answer activities.<br /><b>Available tokens:</b><br />[group:title] - course title,<br />[lms_lesson:label] - lesson title,<br />[lms_activity:label] - activity title,<br />[lms_lesson_status:lesson_number] - lesson number,<br />[lms_lesson_status:activity_number] - activity number.'),
      '#config_target' => 'lms.settings:activity_page_title',
    ];

    return parent::buildForm($form, $form_state);
  }

}
