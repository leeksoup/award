<?php

namespace Drupal\anu_lms\Form;

use Drupal\anu_lms\Settings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity labels form.
 */
class LabelsForm extends ConfigFormBase {

  /**
   * Settings instance.
   *
   * @var \Drupal\anu_lms\Settings
   */
  protected Settings $settings;

  /**
   * AdminToolbarToolsSettingsForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The factory for configuration objects.
   * @param \Drupal\anu_lms\Settings $settings
   *   The Settings instance.
   */
  public function __construct(ConfigFactoryInterface $configFactory, Settings $settings) {
    parent::__construct($configFactory);
    $this->settings = $settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('anu_lms.settings'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'anu_lms.entity_labels',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'admin_anu_lms_entity_labels';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $settings = $this->config('anu_lms.entity_labels');

    $form['labels'] = [
      '#type' => 'details',
      '#title' => $this->t('Entity labels'),
      '#open' => TRUE,
    ];

    $form['labels']['help'] = [
      '#type' => 'markup',
      '#markup' => 'Read more about available plural formats for every language in "cardinal" type for necessary language <a href="https://unicode-org.github.io/cldr-staging/charts/latest/supplemental/language_plural_rules.html" target="_blank">there</a>. The amount of plural forms is equal to the amount of cardinals on that page.',
    ];

    $form['labels']['couses_page'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => 'Courses page',
    ];

    $form['labels']['courses_page_labels'] = [
      '#type' => 'textfield',
      '#name' => 'courses_page_labels',
      '#title' => t('Singular and plural forms'),
      '#default_value' => $settings->get('courses_page_labels'),
      '#size' => 80,
      '#required' => TRUE,
      '#description' => $this->t('Put the forms using "singular|plural" format, can have extra options depending on the language.'),
    ];

    $form['labels']['courses_page_label_plural'] = [
      '#type' => 'textfield',
      '#name' => 'courses_page_label_plural',
      '#title' => t('Plural form without a certain amount'),
      '#default_value' => $settings->get('courses_page_label_plural'),
      '#size' => 80,
      '#required' => TRUE,
      '#description' => $this->t('Used for the context without a certain amount of items.'),
    ];

    $form['labels']['hr1'] = [
      '#type' => 'html_tag',
      '#tag' => 'hr',
    ];

    $form['labels']['course'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => 'Course',
    ];

    $form['labels']['course_labels'] = [
      '#type' => 'textfield',
      '#name' => 'course_labels',
      '#title' => t('Singular and plural forms'),
      '#default_value' => $settings->get('course_labels'),
      '#size' => 80,
      '#required' => TRUE,
      '#description' => $this->t('Put the forms using "singular|plural" format, can have extra options depending on the language.'),
    ];

    $form['labels']['course_label_plural'] = [
      '#type' => 'textfield',
      '#name' => 'course_label_plural',
      '#title' => t('Plural form without a certain amount'),
      '#default_value' => $settings->get('course_label_plural'),
      '#size' => 80,
      '#required' => TRUE,
      '#description' => $this->t('Used for the context without a certain amount of items.'),
    ];

    $form['labels']['hr2'] = [
      '#type' => 'html_tag',
      '#tag' => 'hr',
    ];

    $form['labels']['lesson'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => 'Lesson',
    ];

    $form['labels']['lesson_labels'] = [
      '#type' => 'textfield',
      '#name' => 'lesson_labels',
      '#title' => t('Singular and plural forms'),
      '#default_value' => $settings->get('lesson_labels'),
      '#size' => 80,
      '#required' => TRUE,
      '#description' => $this->t('Put the forms using "singular|plural" format, can have extra options depending on the language.'),
    ];

    $form['labels']['lesson_label_plural'] = [
      '#type' => 'textfield',
      '#name' => 'lesson_label_plural',
      '#title' => t('Plural form without a certain amount'),
      '#default_value' => $settings->get('lesson_label_plural'),
      '#size' => 80,
      '#required' => TRUE,
      '#description' => $this->t('Used for the context without a certain amount of items.'),
    ];

    $form['labels']['hr3'] = [
      '#type' => 'html_tag',
      '#tag' => 'hr',
    ];

    $form['labels']['assessment'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => 'Assessment',
    ];

    $form['labels']['assessment_labels'] = [
      '#type' => 'textfield',
      '#name' => 'assessment_labels',
      '#title' => t('Singular and plural forms'),
      '#default_value' => $settings->get('assessment_labels'),
      '#size' => 80,
      '#required' => TRUE,
      '#description' => $this->t('Put the forms using "singular|plural" format, can have extra options depending on the language. "Lesson|Lessons" by default.'),
    ];

    $form['labels']['assessment_label_plural'] = [
      '#type' => 'textfield',
      '#name' => 'assessment_label_plural',
      '#title' => t('Plural form without a certain amount'),
      '#default_value' => $settings->get('assessment_label_plural'),
      '#size' => 80,
      '#required' => TRUE,
      '#description' => $this->t('Used for the context without a certain amount of items.'),
    ];

    $form['labels']['hr4'] = [
      '#type' => 'html_tag',
      '#tag' => 'hr',
    ];

    $form['labels']['module'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => 'Module',
    ];

    $form['labels']['module_labels'] = [
      '#type' => 'textfield',
      '#name' => 'module_labels',
      '#title' => t('Singular and plural forms'),
      '#default_value' => $settings->get('module_labels'),
      '#size' => 80,
      '#required' => TRUE,
      '#description' => $this->t('Set in "singular|plural" format, can have extra options depending on the language. "Module|Modules" by default.'),
    ];

    $form['labels']['module_label_plural'] = [
      '#type' => 'textfield',
      '#name' => 'module_label_plural',
      '#title' => t('Plural form without a certain amount'),
      '#default_value' => $settings->get('module_label_plural'),
      '#size' => 80,
      '#required' => TRUE,
      '#description' => $this->t('Used for the context without a certain amount of items.'),
    ];

    $form = parent::buildForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $settings = $this->configFactory->getEditable('anu_lms.entity_labels');

    $settings->set('courses_page_labels', $form_state->getValue('courses_page_labels'));
    $settings->set('courses_page_label_plural', $form_state->getValue('courses_page_label_plural'));
    $settings->set('course_labels', $form_state->getValue('course_labels'));
    $settings->set('course_label_plural', $form_state->getValue('course_label_plural'));
    $settings->set('lesson_labels', $form_state->getValue('lesson_labels'));
    $settings->set('lesson_label_plural', $form_state->getValue('lesson_label_plural'));
    $settings->set('assessment_labels', $form_state->getValue('assessment_labels'));
    $settings->set('assessment_label_plural', $form_state->getValue('assessment_label_plural'));
    $settings->set('module_labels', $form_state->getValue('module_labels'));
    $settings->set('module_label_plural', $form_state->getValue('module_label_plural'));

    $settings->save();

    parent::submitForm($form, $form_state);
  }

}
