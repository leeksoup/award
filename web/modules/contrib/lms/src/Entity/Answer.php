<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Answer entity.
 *
 * @ContentEntityType(
 *   id = "lms_answer",
 *   label = @Translation("Answer"),
 *   handlers = {
 *     "storage" = "Drupal\lms\Entity\Handlers\AdaptiveLmsStatusStorage",
 *     "view_builder" = "Drupal\lms\Entity\Handlers\AnswerViewBuilder",
 *     "views_data" = "Drupal\lms\Entity\Handlers\AnswerViewsData",
 *     "form" = {
 *       "default" = "Drupal\lms\Entity\Form\AnswerForm",
 *       "evaluate" = "Drupal\lms\Entity\Form\AnswerEvaluationForm",
 *     },
 *     "access" = "Drupal\lms\Entity\Handlers\AnswerAccessControlHandler",
 *   },
 *   field_ui_base_route = "entity.lms_answer.edit_form",
 *   base_table = "lms_answer",
 *   data_table = "lms_answer_field_data",
 *   admin_permission = "administer lms",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "user_id",
 *     "langcode" = "langcode",
 *     "status" = "status",
 *   },
 *   links = {
 *     "edit-form" = "/answer/{lms_answer}/evaluate",
 *   }
 * )
 */
class Answer extends ContentEntityBase implements AnswerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values): void {
    parent::preCreate($storage_controller, $values);
    $values += [
      'user_id' => \Drupal::currentUser()->id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getActivity(): ActivityInterface {
    $activity = $this->get(self::ACTIVITY_FIELD)->entity;
    \assert($activity instanceof ActivityInterface);
    return $activity;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->t("Answer to @activity by @student", [
      '@student' => $this->getOwner()->label(),
      '@activity' => $this->getActivity()->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getLessonStatus(): LessonStatusInterface {
    $lesson_status = $this->get('lesson_status')->entity;
    \assert($lesson_status instanceof LessonStatusInterface);
    return $lesson_status;
  }

  /**
   * {@inheritdoc}
   */
  public function isEvaluated(): bool {
    return (bool) $this->get('evaluated')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setEvaluated(bool $evaluated): self {
    $this->set('evaluated', $evaluated);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setScore(int|float $score): self {
    $this->set('score', \round($score));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getScore(): int {
    return (int) $this->get('score')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setData(array $data): self {
    $this->set('data', $data);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(): array {
    $item = $this->get('data')->first();
    if ($item === NULL) {
      return [];
    }
    return Json::decode($item->getValue()['value']);
  }

  /**
   * {@inheritdoc}
   */
  public function getUserId(): string {
    return $this->get('user_id')->target_id;
  }

  /**
   * {@inheritdoc}
   *
   * @param mixed $name
   *   Parent class type hint missing.
   * @param mixed $value
   *   Parent class type hint missing.
   * @param mixed $notify
   *   Parent class type hint missing.
   */
  public function set($name, $value, $notify = TRUE): self {
    if ($name === 'data') {
      // Use JSON as data serialization method to allow only pure data
      // with no possibility to pass serialized objects with callable methods.
      $value = Json::encode($value);
    }
    return parent::set($name, $value, $notify);
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(\t('Authored by'))
      ->setDescription(\t('The user ID of author of the Answer entity.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default');

    // Legacy field.
    // @todo Remove in the next major.
    $fields['activity'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(\t('Activity'))
      ->setSetting('target_type', 'lms_activity')
      ->setDisplayConfigurable('view', FALSE)
      ->setReadOnly(TRUE);

    $fields[self::ACTIVITY_FIELD] = BaseFieldDefinition::create('lms_revision_reference')
      ->setLabel(\t('Activity'))
      ->setSetting('target_type', 'lms_activity')
      ->setDisplayConfigurable('view', TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ]);

    $fields['evaluated'] = BaseFieldDefinition::create('boolean')
      ->setLabel(\t('Evaluation status'))
      ->setDescription(\t('A boolean indicating whether the answer is evaluated.'))
      ->setDefaultValue(FALSE);

    $fields['lesson_status'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(\t('Lesson status'))
      ->setSetting('target_type', 'lms_lesson_status');

    $fields['score'] = BaseFieldDefinition::create('integer')
      ->setLabel(\t('Score'))
      ->setDescription(\t('The score the user obtained for this answer'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(\t('Created'))
      ->setDescription(\t('The time that the entity was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(\t('Changed'))
      ->setDescription(\t('The time that the entity was last edited.'));

    // Store answer data in JSON format.
    $fields['data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(\t('Answer data'))
      ->setDescription(\t('Stores answer data'));

    return $fields;
  }

}
