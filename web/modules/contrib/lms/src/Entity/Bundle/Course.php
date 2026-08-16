<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Bundle;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Access\GroupAccessResult;
use Drupal\group\Entity\Group;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem;
use Drupal\lms\Plugin\Field\FieldType\StartLinkFieldItemList;

/**
 * Course group bundle class.
 */
final class Course extends Group {

  public const LESSONS = 'lessons';

  /**
   * Check if the given user can take this learning path.
   */
  public function takeAccess(AccountInterface $account): AccessResult {
    $access = GroupAccessResult::allowedIfHasGroupPermission($this, $account, 'take course')->addCacheableDependency($this);
    if ($this->isPublished() || !$access->isAllowed()) {
      return $access;
    }
    $any_unpublished_access = $access->andIf(GroupAccessResult::allowedIfHasGroupPermission($this, $account, 'view any unpublished group'));
    if ($any_unpublished_access->isAllowed()) {
      return $any_unpublished_access;
    }
    $access_result = GroupAccessResult::allowedIfHasGroupPermission($this, $account, 'view own unpublished group');
    if ($access_result->isAllowed()) {
      $access->addCacheContexts(['user']);
      if ($this->getOwnerId() == $account->id()) {
        return $access->andIf($access_result);
      }
    }
    return $access;
  }

  /**
   * Check if group has guided navigation enabled.
   */
  public function hasFreeNavigation(?AccountInterface $account = NULL): bool {
    // Course editors implicitly have free navigation so they can test any part
    // of the course without taking it linearly.
    if ($account !== NULL && $this->access('update', $account)) {
      return TRUE;
    }

    if (!$this->hasField('free_navigation')) {
      return FALSE;
    }
    return (bool) $this->get('free_navigation')->value;
  }

  /**
   * Get loading conditions when starting / continuing a training.
   *
   * If no course status meeting the given conditions is found, training will be
   * (re) initialized.
   *
   * @return mixed[]
   *   Conditions for loading status entity when starting a course.
   */
  public function getInitialCourseStatusConditions(): array {
    $conditions = [];
    // If revisit mode and ungraded access is disabled only in progress
    // course can be entered.
    if (
      !$this->revisitMode() &&
      !$this->ungradedAccess()
    ) {
      $conditions['finished'] = 0;
    }
    return $conditions;
  }

  /**
   * Check wether the course has revisit mode enabled.
   */
  public function revisitMode(): bool {
    return (bool) $this->get('revisit_mode')->value;
  }

  /**
   * Check if a course that needs grading can be entered.
   */
  public function ungradedAccess(): bool {
    $config = \Drupal::config('lms.settings');
    // FALSE is the default.
    return $config->get('allow_to_enter_ungraded') === TRUE ? TRUE : FALSE;
  }

  /**
   * Get lesson.
   */
  public function getLesson(int $lesson_delta): ?LessonInterface {
    $item = $this->getLessonItem($lesson_delta);
    $lesson = $item->get('entity')->getValue();
    if (!$lesson instanceof LessonInterface) {
      return NULL;
    }

    return $lesson;
  }

  /**
   * Get Lesson field item.
   */
  public function getLessonItem(int $lesson_delta): ?LMSReferenceItem {
    $field = $this->get(self::LESSONS);
    if (
      $field->isEmpty() ||
      !$field->offsetExists($lesson_delta)
    ) {
      return NULL;
    }
    /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem */
    return $field->get($lesson_delta);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(array $values = []) {
    $values += ['type' => 'lms_course'];
    return parent::create($values);
  }

  /**
   * {@inheritdoc}
   */
  public static function bundleFieldDefinitions(EntityTypeInterface $entity_type, mixed $bundle, array $base_field_definitions): array {
    $fields = parent::bundleFieldDefinitions($entity_type, $bundle, $base_field_definitions);

    $fields[self::LESSONS] = BaseFieldDefinition::create('lms_reference')
      ->setLabel(\t('Lessons'))
      ->setDescription(\t('Lesson entity references plus data specific for the lesson.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'lms_lesson')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', [
        'type' => 'lms_reference_table',
        'settings' => [
          'view_data' => 'lessons_selection.default',
        ],
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->addConstraint('NotNull', [
        'message' => new TranslatableMarkup('At least one lesson needs to be a part of the course.'),
      ])
      ->addConstraint('UnpublishedChildConstraint');

    $fields['revisit_mode'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Allow to revisit finished course'))
      ->setDescription(new TranslatableMarkup('Always allow to go back to the training, navigate freely and correct answers without the need to restart.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(FALSE)
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => TRUE,
        ],
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE);
    $fields['free_navigation'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Free navigation'))
      ->setDescription(new TranslatableMarkup('Check this to allow students to go back in a course or jump to any activity (always allowed when revisiting). Mandatory lessons are still required to pass but not to proceed to next lessons.'))
      ->setDefaultValue(FALSE)
      ->setRevisionable(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => TRUE,
        ],
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['start_link'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Course start link'))
      ->setTranslatable(FALSE)
      ->setCardinality(1)
      ->setComputed(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'lms_component_formatter',
        'label' => 'hidden',
        'weight' => 10,
        'region' => 'content',
      ])
      ->setClass(StartLinkFieldItemList::class);

    return $fields;
  }

}
