<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Activity entity.
 *
 * @ContentEntityType(
 *   id = "lms_activity",
 *   label = @Translation("Activity"),
 *   bundle_label = @Translation("Activity type"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\lms\Entity\Handlers\ActivityListBuilder",
 *     "form" = {
 *       "default" = "Drupal\lms\Entity\Form\ActivityForm",
 *       "add" = "Drupal\lms\Entity\Form\ActivityForm",
 *       "edit" = "Drupal\lms\Entity\Form\ActivityForm",
 *       "delete" = "Drupal\lms\Entity\Form\ActivityDeleteForm",
 *       "revision-revert" = "Drupal\lms\Entity\Form\LmsEntityRevisionRevertForm",
 *       "revision-delete" = "Drupal\lms\Entity\Form\LmsEntityRevisionDeleteForm",
 *     },
 *     "access" = "Drupal\lms\Entity\Handlers\ActivityAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\lms\Entity\Handlers\LmsEntityHtmlRouteProvider",
 *       "revision" = "\Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider",
 *     },
 *     "views_data" = "Drupal\lms\Entity\Handlers\LmsEntityViewsDataProvider",
 *   },
 *   base_table = "lms_activity",
 *   data_table = "lms_activity_field_data",
 *   revision_table = "lms_activity_revision",
 *   revision_data_table = "lms_activity_field_revision",
 *   list_cache_contexts = {"user.lms_activity_access"},
 *   translatable = TRUE,
 *   show_revision_ui = TRUE,
 *   admin_permission = "administer lms",
 *   collection_permission = "create lms_activity entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "vid",
 *     "bundle" = "type",
 *     "label" = "name",
 *     "uuid" = "uuid",
 *     "uid" = "uid",
 *     "owner" = "uid",
 *     "published" = "status",
 *     "langcode" = "langcode"
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_user",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log_message",
 *   },
 *   links = {
 *     "canonical" = "/admin/lms/activity/{lms_activity}/edit",
 *     "add-page" = "/admin/lms/activity/add",
 *     "add-form" = "/admin/lms/activity/add/{lms_activity_type}",
 *     "edit-form" = "/admin/lms/activity/{lms_activity}/edit",
 *     "delete-form" = "/admin/lms/activity/{lms_activity}/delete",
 *     "collection" = "/admin/lms/activity",
 *     "revision" = "/admin/lms/activity/{lms_activity}/revisions/{lms_activity_revision}/view",
 *     "revision-revert-form" = "/admin/lms/activity/{lms_activity}/revision/{lms_activity_revision}/revert",
 *     "revision-delete-form" = "/admin/lms/activity/{lms_activity}/revision/{lms_activity_revision}/delete",
 *     "version-history" = "/admin/lms/activity/{lms_activity}/revisions",
 *   },
 *   bundle_entity_type = "lms_activity_type",
 *   field_ui_base_route = "entity.lms_activity_type.edit_form"
 * )
 */
class Activity extends EditorialContentEntityBase implements ActivityInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values): void {
    parent::preCreate($storage_controller, $values);
    $values += [
      'uid' => \Drupal::currentUser()->id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(\t('Authored by'))
      ->setDescription(\t('The user ID of author of the Activity entity.'))
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
      ->setLabel(\t('Name'))
      ->setDescription(\t('The name of the Activity entity.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
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

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(\t('Authored on'))
      ->setDescription(\t('The time that the Activity was created.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(\t('Changed'))
      ->setDescription(\t('The time that the Activity was last edited.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE);

    $fields['status']
      ->setDescription(\t('Unpublished activities cannot be a part of a published lesson.'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->addConstraint('UnpublishedParentConstraint');

    return $fields;
  }

}
