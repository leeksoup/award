<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Hook;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms_classes\ClassHelper;
use Drupal\lms_classes\Service\ClassNameGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Membership form alter hooks logic.
 */
abstract class LmsClassesMembershipHooksBase {

  use StringTranslationTrait;
  use MessengerTrait;

  /**
   * The constructor.
   */
  public function __construct(
    #[Autowire(service: EntityTypeManagerInterface::class, lazy: TRUE)]
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: CacheTagsInvalidatorInterface::class, lazy: TRUE)]
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    #[Autowire(service: ClassNameGenerator::class, lazy: TRUE)]
    protected readonly ClassNameGenerator $classNameGenerator,
    #[Autowire(service: ConfigFactoryInterface::class, lazy: TRUE)]
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Alter group relationship entity forms.
   */
  abstract public function groupRelationshipFormAlter(array &$form, FormStateInterface $form_state): void;

  /**
   * Membership form validate handler.
   */
  public function membershipFormValidate(array $form, FormStateInterface $form_state): void {
    $relationship = self::getGroupRelationship($form_state);
    $course = $relationship->getGroup();
    if (!$course instanceof Course) {
      return;
    }

    $class_id = (string) $form_state->getValue('class');
    if ($class_id === '' || $class_id === '0') {
      return;
    }

    $form_state->setValue('group_roles', []);
    $user_id = $form_state->getValue(['entity_id', '0', 'target_id']);
    /** @var \Drupal\Core\Session\AccountInterface */
    $potential_member = $this->entityTypeManager->getStorage('user')->load($user_id);
    if (ClassHelper::isClassMember($potential_member, $course)) {
      $form_state->setError($form['entity_id']['widget'], $this->t('Selected user is already a member of a class on this Course.'));
      return;
    }

    $this->convertMembership($relationship, $class_id);
  }

  /**
   * Code saver.
   */
  protected function addClassSelector(array &$form, Course $course, bool $self_join = FALSE): void {
    $classes = ClassHelper::getClasses($course);
    $options = [];
    foreach ($classes as $class) {
      $options[$class->id()] = $class->label();
    }
    if (!$self_join) {
      $options['0'] = $this->t('None (Course membership)');
    }
    if (\count($options) === 1) {
      $form['class'] = [
        '#type' => 'value',
        '#value' => \array_keys($options)[0],
      ];
      return;
    }

    $form['class'] = [
      '#type' => 'select',
      '#title' => $this->t('Select target class'),
      '#description' => $self_join ? $this->t('You will be added to the selected class.') : $this->t('Student will be added to the selected class.'),
      '#options' => $options,
      '#weight' => -1,
    ];

    if (\array_key_exists('roles', $form)) {
      $form['roles']['#states'] = [
        'visible' => [
          'select[name=class]' => ['value' => '0'],
        ],
      ];
    }
  }

  /**
   * Group membership or request form submit handler.
   */
  public static function redirectToCourse(array $form, FormStateInterface $form_state): void {
    $relationship = self::getGroupRelationship($form_state);

    // Redirect only if self - adding / requesting.
    if ($relationship->getOwnerId() === $relationship->getEntityId()) {
      $group_id = $form_state->get('redirect_group_id');
      $form_state->setRedirect('entity.group.canonical', ['group' => $group_id]);
    }
  }

  /**
   * Display a status message.
   */
  public function addedToClassMessage(array $form, FormStateInterface $form_state): void {
    $membership = self::getGroupRelationship($form_state);
    $group = $membership->getGroup();
    if ($group->bundle() === 'lms_class') {
      if ($membership->getOwnerId() === $membership->getEntityId()) {
        $this->messenger()->addStatus($this->t('You have been added to the "@class" class.', [
          '@class' => $group->label(),
        ]));
      }
      else {
        $this->messenger()->addStatus($this->t('Student @student has been added to the "@class" class.', [
          '@student' => $membership->getEntity()->label(),
          '@class' => $group->label(),
        ]));
      }
    }
  }

  /**
   * Code saver.
   */
  private static function getGroupRelationship(FormStateInterface $form_state): GroupRelationshipInterface {
    $form_object = $form_state->getFormObject();
    \assert($form_object instanceof ContentEntityFormInterface);
    $relationship = $form_object->getEntity();
    \assert($relationship instanceof GroupRelationshipInterface);
    return $relationship;
  }

  /**
   * Converts membership from Course to Class.
   */
  private function convertMembership(GroupRelationshipInterface $group_relationship, string $class_id): void {
    $group_relationship->set('gid', $class_id);
    $types = $this->entityTypeManager->getStorage('group_relationship_type')->loadByProperties([
      'group_type' => 'lms_class',
      'content_plugin' => 'group_membership',
    ]);
    $type = \reset($types);
    $group_relationship->set('type', $type->id());
    $group_relationship->set('group_type', 'lms_class');
  }

}
