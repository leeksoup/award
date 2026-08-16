<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Exception\TrainingException;

/**
 * Defines the course status entity.
 *
 * @ContentEntityType(
 *   id = "lms_course_status",
 *   label = @Translation("Course Status"),
 *   handlers = {
 *     "storage" = "Drupal\lms\Entity\Handlers\AdaptiveLmsStatusStorage",
 *     "views_data" = "Drupal\lms\Entity\Handlers\CourseStatusViewsData",
 *   },
 *   base_table = "lms_course_status",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "uid" = "uid",
 *   }
 * )
 */
class CourseStatus extends ContentEntityBase implements CourseStatusInterface {

  /**
   * {@inheritdoc}
   */
  public function getCourseId(): string {
    // Not useless actually as this can be int contradictory to some PHP doc.
    // @phpstan-ignore cast.useless
    return (string) $this->get(self::COURSE_FIELD)->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getCourse(): Course {
    /** @var \Drupal\Core\Entity\EntityInterface|null */
    $course = $this->get(self::COURSE_FIELD)->entity;
    if (!$course instanceof Course) {
      throw new TrainingException(NULL, TrainingException::COURSE_REMOVED);
    }
    return $course;
  }

  /**
   * {@inheritdoc}
   */
  public function getUserId(): string {
    // Target_id is typed string but can actually return int,
    // see Drupal\user\EntityOwnerInterface::setOwnerId())
    // @phpstan-ignore cast.useless
    return (string) $this->get('uid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getUser(): AccountInterface {
    $account = $this->get('uid')->entity;
    \assert($account instanceof AccountInterface);
    return $account;
  }

  /**
   * {@inheritdoc}
   */
  public function setUser(AccountInterface $account): self {
    $this->set('uid', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    $status_field = $this->get('status');
    return $status_field->isEmpty() ? '' : $status_field->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): void {
    $this->set('status', $status);
  }

  /**
   * {@inheritdoc}
   */
  public function getScore(): ?int {
    $score = $this->get('score')->value;
    return $score === NULL ? NULL : (int) $score;
  }

  /**
   * {@inheritdoc}
   */
  public function setScore(?int $value): void {
    $this->set('score', $value);
  }

  /**
   * {@inheritdoc}
   */
  public function getFinished(): int {
    $field = $this->get('finished');
    if ($field->isEmpty()) {
      return 0;
    }
    return (int) $field->first()->getValue()['value'];
  }

  /**
   * {@inheritdoc}
   */
  public function setFinished(int $timestamp): self {
    $this->set('finished', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isFinished(): bool {
    return $this->getFinished() > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getStarted(): int {
    return $this->get('started')->value ?? 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getLastActivity(): int {
    return (int) $this->get('last_activity_ts')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setLastActivity(int $timestamp): self {
    $this->set('last_activity_ts', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function allowAnyActivity(): bool {
    $course = $this->getCourse();
    if ($course->hasFreeNavigation($this->getUser())) {
      return TRUE;
    }

    return $course->revisitMode() && $this->isFinished();
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    // Ensure we have only 1 in progress course status per user and course.
    if (!$this->isFinished()) {
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition(self::COURSE_FIELD, $this->get(self::COURSE_FIELD)->target_id)
        ->condition('uid', $this->uid->target_id)
        ->condition('finished', 0);

      if (!$this->isNew()) {
        $query->condition('id', $this->id(), '<>');
      }
      $result = $query->execute();

      if (\count($result) !== 0) {
        throw new \InvalidArgumentException(\sprintf('User %s already has a course status on course %s.', $this->uid->target_id, $this->get(self::COURSE_FIELD)->target_id));
      }
    }

    // Set started timestamp if not already set.
    if ($this->isNew() && $this->get('started')->first()->get('value')->getValue() === 0) {
      $this->set('started', \Drupal::time()->getRequestTime());
    }

    // We can only have one current course status per user and course.
    if ($this->isNew() && $this->isCurrent()) {
      foreach ($storage->loadByProperties([
        self::COURSE_FIELD => $this->get(self::COURSE_FIELD)->target_id,
        'uid' => $this->uid->target_id,
        'current' => TRUE,
      ]) as $course_status) {
        \assert($course_status instanceof CourseStatusInterface);
        $course_status->set('current', FALSE)->save();
      }
    }

    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentLessonStatus(): ?LessonStatusInterface {
    $field = $this->get('current_lesson_status');
    if ($field->isEmpty()) {
      return NULL;
    }
    if (!$field->entity instanceof LessonStatusInterface) {
      return NULL;
    }
    return $field->entity;
  }

  /**
   * Is this the current course status?
   */
  private function isCurrent(): bool {
    return (bool) $this->get('current')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = [];
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(\t('ID'))
      ->setDescription(\t('The ID of the Course Status entity.'))
      ->setReadOnly(TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(\t('UUID'))
      ->setDescription(\t('The UUID of the Course Status.'))
      ->setReadOnly(TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(\t('User'))
      ->setDescription(\t('The user ID of the Course Status entity.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE);

    // Legacy field.
    // @todo Remove in the next major.
    $fields['gid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(\t('Course'))
      ->setDescription(\t('The Course the status entity refers to.'))
      ->setSettings([
        'target_type' => 'group',
        'default_value' => 0,
      ])
      ->setRequired(TRUE);

    $fields[self::COURSE_FIELD] = BaseFieldDefinition::create('lms_revision_reference')
      ->setLabel(\t('Course'))
      ->setDescription(\t('The Course the status entity refers to.'))
      ->setSettings([
        'target_type' => 'group',
        'default_value' => 0,
      ])
      ->setRequired(TRUE);

    $fields['score'] = BaseFieldDefinition::create('integer')
      ->setLabel(\t('Score'))
      ->setDescription(\t('The score the user obtained on the course.'));

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(\t('Status'))
      ->setDescription(\t('The training status - in progress / passed / failed.'))
      ->setSettings([
        'max_length' => 15,
      ])
      ->setDefaultValue('');

    $fields['current_lesson_status'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(\t('Current lesson status'))
      ->setDescription(\t('The current lesson status to be started / continued'))
      ->setSetting('target_type', 'lms_lesson_status');

    $fields['started'] = BaseFieldDefinition::create('created')
      ->setLabel(\t('Started'))
      ->setDescription(\t('The time that the course was started.'))
      ->setDefaultValue(0);

    $fields['last_activity_ts'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(\t('Last activity timestamp'))
      ->setDescription(\t('The time of submitting the last answer by the student or starting of the course if there are no answers yet.'))
      ->setDefaultValue(0);

    $fields['finished'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(\t('Finished'))
      ->setDescription(\t('The time that the course finished.'))
      ->setDefaultValue(0);

    // Needed for Views so we can join and display the current status
    // without having duplicates.
    $fields['current'] = BaseFieldDefinition::create('boolean')
      ->setLabel(\t('Current status'))
      ->setDescription(\t('A flag indicating whether this is the current status.'))
      ->setDefaultValue(TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatusAndScore(): string {
    $status = $this->getStatus();
    $status_text = (string) self::getStatusText($status);
    if (\in_array($status, [
      self::STATUS_PASSED,
      self::STATUS_FAILED,
    ], TRUE)) {
      $status_text .= ' (' . $this->getScore() . '%)';
    }
    return $status_text;
  }

  /**
   * {@inheritdoc}
   */
  public static function getStatusOptions(): array {
    return [
      self::STATUS_PROGRESS => new TranslatableMarkup('In progress'),
      self::STATUS_NEEDS_EVALUATION => new TranslatableMarkup('Needs grading'),
      self::STATUS_PASSED => new TranslatableMarkup('Passed'),
      self::STATUS_FAILED => new TranslatableMarkup('Failed'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getStatusText(string $status): TranslatableMarkup {
    return static::getStatusOptions()[$status] ?? new TranslatableMarkup('N/A');
  }

}
