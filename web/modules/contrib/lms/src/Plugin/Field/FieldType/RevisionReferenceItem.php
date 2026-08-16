<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Field\FieldType;

use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\lms\RevisionLoadChecker;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataReferenceTargetDefinition;

/**
 * Defines the 'lms_revision_reference' entity field type.
 */
#[FieldType(
  id: "lms_revision_reference",
  label: new TranslatableMarkup("LMS revision reference"),
  description: new TranslatableMarkup("An entity field containing an entity revision reference."),
  category: "reference",
  list_class: EntityReferenceFieldItemList::class,
)]
final class RevisionReferenceItem extends EntityReferenceItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $definitions = parent::propertyDefinitions($field_definition);
    $definitions['vid'] = DataReferenceTargetDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Revision ID'))
      ->setSetting('unsigned', TRUE)
      ->setRequired(TRUE);

    return $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = parent::schema($field_definition);
    $schema['columns']['vid'] = [
      'description' => 'The revision ID of the target entity.',
      'type' => 'int',
      'unsigned' => TRUE,
    ];
    $schema['indexes']['vid'] = ['vid'];
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(): void {
    parent::preSave();
    if (!$this->entity instanceof RevisionableInterface) {
      return;
    }

    if ($this->hasNewEntity()) {
      // Save the entity if it has not already been saved by some other code.
      if ($this->entity->isNew()) {
        $this->entity->save();
      }
      $this->set('vid', $this->entity->getRevisionId(), FALSE);
    }
    elseif (!$this->isEmpty() && $this->get('vid')->getValue() === NULL) {
      $this->set('vid', $this->entity->getRevisionId(), FALSE);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __get($name): mixed {
    return $this->getRevision(parent::__get($name));
  }

  /**
   * {@inheritdoc}
   */
  public function get($property_name) {
    $value = parent::get($property_name);
    if ($value instanceof EntityReference) {
      $value->setValue($this->getRevision($value->getValue()), FALSE);
    }
    return $value;
  }

  /**
   * Entity revision case.
   */
  public function getRevision(mixed $value): mixed {
    if (!$value instanceof RevisionableInterface) {
      return $value;
    }
    $vid = $this->get('vid')->getValue();
    if (
      $vid === NULL ||
      $vid === $value->getRevisionId()
    ) {
      return $value;
    }
    if (!\Drupal::service(RevisionLoadChecker::class)->shouldLoadRevision()) {
      return $value;
    }

    /** @var \Drupal\Core\Entity\RevisionableStorageInterface */
    $storage = \Drupal::entityTypeManager()->getStorage($value->getEntityTypeId());
    $revision = $storage->loadRevision($vid);
    if ($revision === NULL) {
      return $value;
    }
    return $revision;
  }

  /**
   * {@inheritdoc}
   */
  public static function getPreconfiguredOptions() {
    // We don't want to see this all over the fields admin UI.
    return [];
  }

}
