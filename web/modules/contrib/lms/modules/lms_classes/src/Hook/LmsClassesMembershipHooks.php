<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\Form\GroupRelationshipDeleteForm;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Plugin\Block\CourseStartLink;
use Drupal\lms_classes\ClassHelper;

/**
 * Membership form alter hooks logic.
 */
final class LmsClassesMembershipHooks extends LmsClassesMembershipHooksBase {

  /**
   * Alter group relationship entity forms.
   */
  #[Hook('form_group_relationship_form_alter')]
  #[Hook('form_group_relationship_confirm_form_alter')]
  public function groupRelationshipFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (
      !$form_object instanceof ContentEntityFormInterface ||
      $form_object instanceof GroupRelationshipDeleteForm
    ) {
      return;
    }

    $relationship = $form_object->getEntity();
    \assert($relationship instanceof GroupRelationshipInterface);
    $relationship_plugin_id = $relationship->getPluginId();
    if ($relationship_plugin_id !== 'group_membership') {
      return;
    }

    $course = $relationship->getGroup();
    if (!$course instanceof Course) {
      return;
    }

    $form_state->set('redirect_group_id', $course->id());
    $form['actions']['submit']['#submit'][] = [
      self::class,
      'redirectToCourse',
    ];
    $form['actions']['cancel'] = Link::createFromRoute($this->t('Cancel'),
      'entity.group.canonical',
      ['group' => $course->id()],
      ['attributes' => ['class' => ['button']]],
    )->toRenderable();

    $self_join = $relationship->getOwnerId() === $relationship->getEntityId();
    if ($self_join) {
      $form['actions']['submit']['#value'] = $this->t('Join training');
    }
    \array_unshift($form['#validate'], [
      $this,
      'membershipFormValidate',
    ]);

    $this->addClassSelector($form, $course, $self_join);

    $form['actions']['submit']['#submit'][] = [
      $this,
      'addedToClassMessage',
    ];
  }

  /**
   * Invalidate cache tags when a group relationship is saved or deleted.
   */
  #[Hook('group_relationship_presave')]
  #[Hook('group_relationship_delete')]
  public function invalidateRelationshipTags(GroupRelationshipInterface $group_relationship): void {
    // Invalidate course classes tag.
    if ($group_relationship->getPluginId() === 'lms_classes') {
      $parent_group = $group_relationship->getGroup();
      if (!$parent_group instanceof Course) {
        return;
      }
      $this->cacheTagsInvalidator->invalidateTags(['group.classes:' . $parent_group->id()]);
    }

  }

  /**
   * Alter the course start link.
   */
  #[Hook('lms_course_link')]
  public function lmsCourseLink(Course $course, AccountInterface $current_user, CacheableMetadata $cacheability): array {
    $cacheability->addCacheTags(['group.classes:' . $course->id()]);

    if (!$course->takeAccess($current_user)->isAllowed()) {
      $classes = ClassHelper::getClasses($course);
      if (\count($classes) === 0) {
        return CourseStartLink::buildActionInfo('no-classes', $this->t('No open classes'));
      }
    }

    return [];
  }

  /**
   * Create default class when a course is created.
   */
  #[Hook('group_insert')]
  public function groupInsert(GroupInterface $group): void {
    if ($group->bundle() === 'lms_class') {
      $this->classNameGenerator->setCurrentClassNumber();
      return;
    }

    if (!$group instanceof Course || $group->isSyncing()) {
      return;
    }

    if ($this->configFactory->get('lms_classes.settings')->get('create_default_class') !== TRUE) {
      return;
    }

    // Add a default class on learning path creation.
    /**
     * @var \Drupal\group\Entity\GroupInterface $class
     */
    $class = $this->entityTypeManager->getStorage('group')->create([
      'type' => 'lms_class',
      'label' => $this->classNameGenerator->generateDefaultClassName($group),
      'uid' => $group->getOwnerId(),
    ]);
    $class->save();

    // Always add the creator membership.
    $group_type = $class->getGroupType();
    if ($group_type->creatorMustCompleteMembership()) {
      $values = ['group_roles' => $group_type->getCreatorRoleIds()];
      $class->addMember($class->getOwner(), $values);
    }

    $group->addRelationship($class, 'lms_classes');
  }

}
