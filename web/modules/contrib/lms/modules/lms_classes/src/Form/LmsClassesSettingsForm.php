<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms_classes\Service\ClassNameGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form builder class for general LMS settings.
 */
final class LmsClassesSettingsForm extends ConfigFormBase {

  /**
   * Class name generator service.
   */
  protected ClassNameGenerator $classNameGenerator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return parent::create($container)->injectServices($container);
  }

  /**
   * Inject services.
   */
  private function injectServices(ContainerInterface $container): self {
    $this->classNameGenerator = $container->get(ClassNameGenerator::class);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lms_classes_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['lms_classes.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['create_default_class'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Default class creation'),
      '#description' => $this->t('Should a default class be created when creating a course?'),
      '#config_target' => 'lms_classes.settings:create_default_class',
    ];
    $form['class_name_pattern'] = [
      '#type' => 'textfield',
      '#maxlength' => 128,
      '#title' => $this->t('Default class name pattern'),
      '#description' => $this->t('Leave empty for "Class [random numbers/letters]".<br />Pattern supports tokens, example: for course name include [group:title].<br /><b>Additional custom tokens:</b><br />[lms_cn:rlX], where X = 0-9 - generate X random uppercase letters,<br />[lms_cn:rdX], where X = 0-9 - generate X random digits,<br />[lms_cn:class_no] - auto-incrementing class number.'),
      '#states' => [
        'visible' => [
          ':input[name="create_default_class"]' => ['checked' => TRUE],
        ],
      ],
      '#config_target' => 'lms_classes.settings:class_name_pattern',
    ];
    $form['permissions_mapping_information'] = [
      '#type' => 'item',
      '#title' => $this->t('Course - Class permission mappings'),
      '#plain_text' => $this->t('Currently editable directly in configuration only. Please use Drush, edit in exported configuration and import or set in project settings.php.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('create_default_class') !== 1) {
      $form_state->setValue('class_name_pattern', '');
    }
    $pattern = $form_state->getValue('class_name_pattern');
    if ($pattern === '' || $pattern === NULL) {
      return;
    }

    $course = Course::create([
      'label' => '[Class name]',
    ]);
    try {
      $class_name = $this->classNameGenerator->generateDefaultClassName($course, $pattern);
      $this->messenger()->addStatus($this->t('Default class names will look like "@name".', [
        '@name' => $class_name,
      ]));
    }
    catch (\Exception $e) {
      $form_state->setError($form['class_name_pattern'], $e->getMessage());
    }
  }

}
