<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Entity\Handlers\LmsLessonHandlerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Lesson entity.
 *
 * @ContentEntityType(
 *   id = "lms_lesson",
 *   label = @Translation("Lesson"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\lms\Entity\Handlers\LessonListBuilder",
 *
 *     "form" = {
 *       "default" = "Drupal\lms\Entity\Form\LessonForm",
 *       "add" = "Drupal\lms\Entity\Form\LessonForm",
 *       "edit" = "Drupal\lms\Entity\Form\LessonForm",
 *       "delete" = "Drupal\lms\Entity\Form\LessonDeleteForm",
 *       "revision-revert" = "Drupal\lms\Entity\Form\LmsEntityRevisionRevertForm",
 *       "revision-delete" = "Drupal\lms\Entity\Form\LmsEntityRevisionDeleteForm",
 *     },
 *     "access" = "Drupal\lms\Entity\Handlers\LessonAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\lms\Entity\Handlers\LmsEntityHtmlRouteProvider",
 *       "revision" = "\Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider",
 *     },
 *     "views_data" = "Drupal\lms\Entity\Handlers\LmsEntityViewsDataProvider",
 *   },
 *   base_table = "lms_lesson",
 *   data_table = "lms_lesson_field_data",
 *   revision_table = "lms_lesson_revision",
 *   revision_data_table = "lms_lesson_field_revision",
 *   translatable = TRUE,
 *   show_revision_ui = TRUE,
 *   admin_permission = "administer lms",
 *   collection_permission = "create lms_lesson entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "bundle" = "lms_lesson_type",
 *     "revision" = "vid",
 *     "label" = "name",
 *     "uuid" = "uuid",
 *     "uid" = "uid",
 *     "owner" = "uid",
 *     "published" = "status",
 *     "langcode" = "langcode",
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_user",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log_message",
 *   },
 *   links = {
 *     "canonical" = "/admin/lms/lesson/{lms_lesson}/edit",
 *     "add-page" = "/admin/lms/lesson/add",
 *     "add-form" = "/admin/lms/lesson/add/{lms_lesson_type}",
 *     "edit-form" = "/admin/lms/lesson/{lms_lesson}/edit",
 *     "delete-form" = "/admin/lms/lesson/{lms_lesson}/delete",
 *     "collection" = "/admin/lms/lesson",
 *     "revision" = "/admin/lms/lesson/{lms_lesson}/revisions/{lms_lesson_revision}/view",
 *     "revision-delete-form" = "/admin/lms/lesson/{lms_lesson}/revision/{lms_lesson_revision}/delete",
 *     "revision-revert-form" = "/admin/lms/lesson/{lms_lesson}/revisions/{lms_lesson_revision}/revert",
 *     "version-history" = "/admin/lms/lesson/{lms_lesson}/revisions",
 *   },
 *   field_ui_base_route = "lms.lesson_type.settings",
 *   list_cache_contexts = {"user.lms_lesson_access"},
 * )
 */
class Lesson extends EditorialContentEntityBase implements LessonInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values): void {
    parent::preCreate($storage_controller, $values);
    $values += [
      'uid' => \Drupal::currentUser()->id(),
      'lms_lesson_type' => 'lesson',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): ?int {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime(int $timestamp): LessonInterface {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getRandomization(): int {
    return (int) $this->get('randomization')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getBackwardsNavigation(): bool {
    return (bool) $this->get('backwards_navigation')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getRandomActivitiesCount(): int {
    return (int) $this->get('random_activities')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setRandomActivitiesCount(int $activityCount): self {
    $this->set('random_activities', $activityCount);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getLessonHandlerService(): LmsLessonHandlerInterface {
    return \Drupal::service('lms.lesson_handler.default');
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Authored by'))
      ->setDescription(new TranslatableMarkup('The user ID of author of the Lesson entity.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'author',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setDescription(new TranslatableMarkup('The name of the Lesson entity.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDefaultValue(NULL)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDefaultValue('')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setRequired(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Authored on'))
      ->setDescription(new TranslatableMarkup('The time that the Lesson was created.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'timestamp',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the Lesson was last edited.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE);

    $fields['backwards_navigation'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Backwards navigation'))
      ->setDescription(new TranslatableMarkup('Allow users to go back and revisit activities already answered.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['randomization'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Randomize activities'))
      ->setDescription(new TranslatableMarkup("<strong>Random order</strong> - all activities display in random order") . '<br/>' . new TranslatableMarkup("<strong>Random activities</strong> - specific number of activities are drawn randomly from this lesson's pool"))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setSetting('allowed_values', [
        0 => new TranslatableMarkup('No randomization'),
        1 => new TranslatableMarkup('Random order'),
        2 => new TranslatableMarkup('Random activities'),
      ])
      ->setDefaultValue(0)
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['random_activities'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Number of random activities'))
      ->setDescription(new TranslatableMarkup('The number of activities to be randomly selected each time someone takes this lesson.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(1)
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields[self::ACTIVITIES] = BaseFieldDefinition::create('lms_reference')
      ->setLabel(new TranslatableMarkup('Activities'))
      ->setDescription(new TranslatableMarkup('Activity entity references plus data specific for this lesson.'))
      ->setRevisionable(TRUE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'lms_activity')
      ->setDisplayOptions('form', [
        'type' => 'lms_reference_table',
        'settings' => [
          'view_data' => 'activities_selection.default',
        ],
        'weight' => 6,
      ])
      ->addConstraint('NotNull', [
        'message' => new TranslatableMarkup('At least one activity needs to be a part of the lesson.'),
      ])
      ->addConstraint('UnpublishedChildConstraint')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status']
      ->setDescription(new TranslatableMarkup('Unpublished lessons cannot be a part of a published course.'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->addConstraint('UnpublishedParentConstraint')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
