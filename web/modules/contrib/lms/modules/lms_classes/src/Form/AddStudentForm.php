<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms_classes\ClassHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add student to course class.
 */
final class AddStudentForm extends FormBase {

  /**
   * Constructor.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static($container->get(EntityTypeManagerInterface::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lms_add_student';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Course $group = NULL): array {
    if ($group === NULL) {
      return [];
    }

    $class_options = [];
    foreach (ClassHelper::getClasses($group) as $class) {
      $class_options[$class->id()] = $class->label();
    }

    $form['class'] = [
      '#type' => 'select',
      '#title' => $this->t('Class'),
      '#options' => $class_options,
    ];

    $form['student'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Student'),
      '#target_type' => 'user',
      '#selection_settings' => ['include_anonymous' => FALSE],
      '#required' => TRUE,
    ];

    $roles = $this->entityTypeManager->getStorage('group_role')->loadByProperties([
      'group_type' => 'lms_class',
      'scope' => 'individual',
    ]);
    $role_options = [];
    foreach ($roles as $role) {
      $role_options[$role->id()] = $role->label();
    }
    $form['roles'] = [
      '#type' => 'checkboxes',
      '#title' => 'roles',
      '#options' => $role_options,
    ];
    if (\count($role_options) === 0) {
      $form['roles']['#access'] = FALSE;
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Add'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\group\Entity\GroupInterface */
    $class = $this->entityTypeManager->getStorage('group')->load($form_state->getValue('class'));
    /** @var \Drupal\user\UserInterface */
    $student = $this->entityTypeManager->getStorage('user')->load($form_state->getValue('student'));
    if ($class->getMember($student) !== FALSE) {
      $form_state->setError($form['student'], $this->t('@student is already a member of the @class.', [
        '@student' => $student->label(),
        '@class' => $class->label(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\group\Entity\GroupInterface */
    $class = $this->entityTypeManager->getStorage('group')->load($form_state->getValue('class'));
    /** @var \Drupal\user\UserInterface */
    $student = $this->entityTypeManager->getStorage('user')->load($form_state->getValue('student'));
    $roles = \array_filter($form_state->getValue('roles'), fn($role) => $role !== 0);
    $class->addMember($student, [
      'group_roles' => $roles,
    ]);
    $this->messenger()->addStatus($this->t('@student has been added to the @class class.', [
      '@student' => $student->label(),
      '@class' => $class->label(),
    ]));
  }

}
