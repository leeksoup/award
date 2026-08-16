<?php

declare(strict_types=1);

namespace Drupal\lms\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Access\GroupAccessResult;
use Drupal\group\Entity\GroupInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\CourseStatus;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\Entity\Handlers\LmsEntityAccessControlHandlerBase;
use Drupal\lms\Entity\LessonStatus;
use Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem;
use Drupal\lms\TrainingManager;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

/**
 * Centralized entity hooks logic.
 */
final class LmsEntityHooks {

  use StringTranslationTrait;

  /**
   * The constructor.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager', lazy: TRUE)]
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    #[AutowireCallable(service: 'queue', method: 'get', lazy: TRUE)]
    private \Closure $getQueue,
    #[Autowire(service: 'cache_tags.invalidator', lazy: TRUE)]
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    $fields = [];

    // Add required fields globally to group entity type to avoid
    // reports of mismatched definitions and missing storage.
    // Unfortunately definitions in the bundle class are not enough for the
    // time being and using hook_entity_bundle_field_info() and
    // hook_entity_field_storage_info() that seems a bit cleaner completely
    // overrides definitions in the bundle class, making them obsolete.
    // If only storage is defined in hook_entity_field_storage_info, those
    // fields will not be available in layout builder, this however makes
    // them available for all group bundles which is wrong but lesser evil.
    // @todo Wait for times when this gets better support in core.
    // @see Drupal\lms\Entity\Bundle\Course::bundleFieldDefinitions().
    // @see https://www.drupal.org/project/drupal/issues/3045509.
    if ($entity_type->id() === 'group') {
      $fields['lessons'] = BaseFieldDefinition::create('lms_reference')
        ->setLabel($this->t('Course lessons'))
        ->setSetting('target_type', 'lms_lesson')
        ->setRevisionable(TRUE)
        ->setTranslatable(FALSE)
        ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
      $fields['revisit_mode'] = BaseFieldDefinition::create('boolean')
        ->setLabel($this->t('Course revisit mode'))
        ->setRevisionable(TRUE)
        ->setTranslatable(FALSE);
      $fields['free_navigation'] = BaseFieldDefinition::create('boolean')
        ->setLabel($this->t('Course free navigation'))
        ->setRevisionable(TRUE)
        ->setTranslatable(FALSE);
      $fields['start_link'] = BaseFieldDefinition::create('string')
        ->setLabel($this->t('Course start link'))
        ->setComputed(TRUE);
    }

    return $fields;
  }

  #[Hook('entity_bundle_info')]
  public function entityBundleInfo(): array {
    $info = [];
    $info['lms_lesson']['lesson']['label'] = $this->t('Lesson (default)');
    return $info;
  }

  #[Hook('entity_bundle_info_alter')]
  public function entityBundleInfoAlter(array &$bundles): void {
    if (\array_key_exists('group', $bundles) && \array_key_exists('lms_course', $bundles['group'])) {
      $bundles['group']['lms_course']['class'] = Course::class;
    }
  }

  #[Hook('user_delete')]
  public function userDelete(UserInterface $user): void {
    $course_status_ids = $this->entityTypeManager->getStorage('lms_course_status')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $user->id())
      ->execute();
    if (\count($course_status_ids) === 0) {
      return;
    }
    $queue = ($this->getQueue)('lms_delete_entities', TRUE);
    foreach (\array_chunk($course_status_ids, 10) as $ids) {
      $queue->createItem(['entity_type' => 'lms_course_status', 'ids' => $ids]);
    }
  }

  #[Hook('group_delete')]
  public function groupDelete(GroupInterface $group): void {
    if (!$group instanceof Course) {
      return;
    }
    $course_status_ids = $this->entityTypeManager->getStorage('lms_course_status')->getQuery()
      ->accessCheck(FALSE)
      ->condition(CourseStatusInterface::COURSE_FIELD, $group->id())
      ->execute();
    if (\count($course_status_ids) === 0) {
      return;
    }
    $queue = ($this->getQueue)('lms_delete_entities', TRUE);
    foreach (\array_chunk($course_status_ids, 10) as $ids) {
      $queue->createItem(['entity_type' => 'lms_course_status', 'ids' => $ids]);
    }
  }

  #[Hook('lms_course_status_delete')]
  public function lmsCourseStatusDelete(CourseStatus $course_status): void {
    // Queue lesson statuses that reference this Course status for deletion.
    $lesson_status_ids = $this->entityTypeManager->getStorage('lms_lesson_status')->getQuery()
      ->accessCheck(FALSE)
      ->condition('course_status', $course_status->id())
      ->execute();
    $queue = ($this->getQueue)('lms_delete_entities', TRUE);
    foreach (\array_chunk($lesson_status_ids, 10) as $ids) {
      $queue->createItem(['entity_type' => 'lms_lesson_status', 'ids' => $ids]);
    }
    $this->cacheTagsInvalidator->invalidateTags([
      TrainingManager::trainingStatusTag($course_status->getCourseId(), $course_status->getUserId()),
    ]);
  }

  #[Hook('lms_course_status_presave')]
  public function lmsCourseStatusPresave(CourseStatus $course_status): void {
    $this->cacheTagsInvalidator->invalidateTags([
      TrainingManager::trainingStatusTag($course_status->getCourseId(), $course_status->getUserId()),
    ]);
  }

  #[Hook('lms_lesson_status_delete')]
  public function lmsLessonStatusDelete(LessonStatus $lesson_status): void {
    // Queue answers that reference this lesson status for deletion.
    $answer_ids = $this->entityTypeManager->getStorage('lms_answer')->getQuery()
      ->accessCheck(FALSE)
      ->condition('lesson_status', $lesson_status->id())
      ->execute();
    $queue = ($this->getQueue)('lms_delete_entities', TRUE);
    foreach (array_chunk($answer_ids, 10) as $ids) {
      $queue->createItem(['entity_type' => 'lms_answer', 'ids' => $ids]);
    }
  }

  #[Hook('group_access')]
  public function groupAccess(GroupInterface $group, string $operation, AccountInterface $account): AccessResultInterface {
    if ($group instanceof Course) {
      if ($operation === 'take') {
        return $group->takeAccess($account);
      }
      if ($operation === 'results') {
        $access = $group->takeAccess($account);
        if (!$access->isAllowed()) {
          return GroupAccessResult::allowedIfHasGroupPermission($group, $account, 'grade students');
        }
        return $access;
      }
    }

    return AccessResult::neutral();
  }

  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account): AccessResultInterface {
    // LMS reference field edit access - apply to group entity.
    if (
      $operation === 'edit' &&
      $field_definition->getTargetEntityTypeId() === 'group' &&
      $field_definition->getItemDefinition()->getClass() === LMSReferenceItem::class
    ) {
      return LmsEntityAccessControlHandlerBase::lmsReferenceFieldEditAccess('lms_lesson', $account);
    }

    return AccessResult::neutral();
  }

}
