<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Exception\TrainingException;
use Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem;

/**
 * Defines the lesson status entity.
 *
 * @ContentEntityType(
 *   id = "lms_lesson_status",
 *   label = @Translation("Lesson Status"),
 *   handlers = {
 *     "storage" = "Drupal\lms\Entity\Handlers\AdaptiveLmsStatusStorage",
 *     "views_data" = "Drupal\lms\Entity\Handlers\LessonStatusViewsData",
 *   },
 *   base_table = "lms_lesson_status",
 *   data_table = "lms_lesson_status_field_data",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   }
 * )
 */
class LessonStatus extends ContentEntityBase implements LessonStatusInterface {

  /**
   * Cache the current lesson delta.
   */
  private ?int $currentLessonDelta = NULL;

  use EntityChangedTrait;

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
  public function getLesson(): LessonInterface {
    $lesson = $this->get(self::LESSON_FIELD)->entity;
    \assert($lesson instanceof LessonInterface);
    return $lesson;
  }

  /**
   * {@inheritdoc}
   */
  public function getLessonId(): string {
    // Not useless actually as this can be int contradictory to some PHP doc.
    // @phpstan-ignore cast.useless
    return (string) $this->get(self::LESSON_FIELD)->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setFinished(int $timestamp = 0): self {
    if ($timestamp === 0) {
      $timestamp = \Drupal::time()->getRequestTime();
    }
    $this->set('finished', $timestamp);
    $this->get('current_activity')->delete();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFinishedTime(): int {
    $field = $this->get('finished');
    if ($field->isEmpty()) {
      return 0;
    }
    return (int) $field->first()->getValue()['value'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCompletionTime(): int {
    if (!$this->isFinished()) {
      return 0;
    }

    return $this->getFinishedTime() - $this->getCreatedTime();
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('started')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getActivityIds(): array {
    $ids = [];
    $field = $this->get(self::ACTIVITIES_FIELD);
    if ($field->isEmpty()) {
      return $ids;
    }
    foreach ($field as $item) {
      $target_id = (int) $item->get('target_id')->getValue();
      $ids[$target_id] = $target_id;
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getActivities(): array {
    $activities = [];
    // Don't use referencedEntities() to make sure we have correct revisions.
    /** @var \Drupal\lms\Plugin\Field\FieldType\RevisionReferenceItem $item */
    foreach ($this->get(self::ACTIVITIES_FIELD) as $delta => $item) {
      \assert($item->entity instanceof ActivityInterface);
      $activities[$delta] = $item->entity;
    }
    return $activities;
  }

  /**
   * {@inheritdoc}
   */
  public function setActivityIds(array $activity_ids): self {
    $this->set(self::ACTIVITIES_FIELD, $activity_ids);
    return $this;
  }

  /**
   * Get last (final) activity ID in this attempt.
   */
  public function getLastActivityId(): int {
    $activity_ids = $this->getActivityIds();
    if (\count($activity_ids) === 0) {
      throw new \Exception('Trying to get last activity ID for an uninitialized lesson status.');
    }
    return \end($activity_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentActivityDelta(): ?int {
    $delta = $this->get('current_activity')->value;
    if ($delta === NULL) {
      return NULL;
    }
    return (int) $delta;
  }

  /**
   * {@inheritdoc}
   */
  public function setCurrentActivityDelta(?int $delta): self {
    $this->set('current_activity', $delta);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getNextActivityDelta(?ActivityInterface $activity = NULL): ?int {
    if ($activity !== NULL) {
      $activities = $this->get(self::ACTIVITIES_FIELD);
      for ($delta = 0; $delta < $this->getActivityCount(); $delta++) {
        if (!$activities->offsetExists($delta)) {
          return NULL;
        }
        if ((int) $activities->get($delta)->get('target_id')->getValue() === (int) $activity->id()) {
          $delta++;
          break;
        }
      }
    }
    else {
      $delta = $this->getCurrentActivityDelta();
      if ($delta === NULL) {
        return NULL;
      }
      $delta++;
    }

    // @phpstan-ignore-next-line $delta is always defined here.
    return $this->get(self::ACTIVITIES_FIELD)->offsetExists($delta) ? $delta : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getLastActivityDelta(): ?int {
    $count = $this->getActivityCount();
    if ($count === 0) {
      return NULL;
    }
    return $count - 1;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentActivity(): ?ActivityInterface {
    $current_delta = $this->getCurrentActivityDelta();
    if ($current_delta === NULL) {
      return NULL;
    }
    return $this->getActivity($current_delta);
  }

  /**
   * {@inheritdoc}
   */
  public function getActivity(int $delta): ?ActivityInterface {
    $activity_items = $this->get(self::ACTIVITIES_FIELD);
    if (!$activity_items->offsetExists($delta)) {
      return NULL;
    }
    $activity = $activity_items->get($delta)->get('entity')->getValue();
    if (!$activity instanceof ActivityInterface) {
      return NULL;
    }
    return $activity;
  }

  /**
   * {@inheritdoc}
   */
  public function setCurrentLessonDelta(int $delta): void {
    $this->currentLessonDelta = $delta;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentLessonDelta(): int {
    if ($this->currentLessonDelta !== NULL) {
      return $this->currentLessonDelta;
    }
    $current_lesson_id = $this->get(self::LESSON_FIELD)->first()->get('target_id')->getValue();
    foreach ($this->getCourseStatus()->getCourse()->get(Course::LESSONS) as $delta => $item) {
      if ($current_lesson_id === $item->get('target_id')->getValue()) {
        $this->currentLessonDelta = $delta;
        break;
      }
    }
    if ($this->currentLessonDelta === NULL) {
      throw new TrainingException(NULL, TrainingException::LESSON_REMOVED);
    }
    return $this->currentLessonDelta;
  }

  /**
   * Get current lesson LMSReferenceItem.
   */
  protected function getCurrentLessonItem(): LMSReferenceItem {
    $item = $this
      ->getCourseStatus()
      ->getCourse()
      ->get(Course::LESSONS)
      ->get($this->getCurrentLessonDelta());
    \assert($item instanceof LMSReferenceItem);
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function isOverTime(): bool {
    $close_time = $this->getCloseTime();
    if ($close_time === 0) {
      return FALSE;
    }
    $request_time = \Drupal::time()->getRequestTime();
    return $request_time >= $close_time;
  }

  /**
   * {@inheritdoc}
   */
  public function getCloseTime(): int {
    // Exit early if the user is revisiting a finished course.
    if ($this->getCourseStatus()->isFinished()) {
      return 0;
    }
    $time_limit = $this->getCurrentLessonItem()->getTimeLimit();
    if ($time_limit === 0) {
      return 0;
    }
    return ((int) $this->get('started')->value) + $time_limit * 60;
  }

  /**
   * {@inheritdoc}
   */
  public function getNextLessonDelta(?Course $course = NULL): ?int {
    if ($course === NULL) {
      $course = $this->getCourseStatus()->getCourse();
    }
    $next = $this->getCurrentLessonDelta() + 1;
    if ($course->get(Course::LESSONS)->offsetExists($next)) {
      return $next;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isFinished(): bool {
    return $this->getFinishedTime() > 0;
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
  public function setEvaluated(bool $value): self {
    $this->set('evaluated', $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setScore(int $value): self {
    $this->set('score', $value);
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
  public function getRequiredScore(): int {
    return (int) $this->get('required_score')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxScore(): int {
    return (int) $this->get('max_score')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setMaxScore(int $max_score): self {
    $this->set('max_score', $max_score);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setPassPercentage(int $percentage): self {
    $this->set('percent', $percentage);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isMandatory(): bool {
    return (bool) $this->get('mandatory')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getAnswerCount(): int {
    return (int) $this->get('given_answers')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getActivityCount(): int {
    return $this->get(self::ACTIVITIES_FIELD)->count();
  }

  /**
   * {@inheritdoc}
   */
  public function getCourseStatus(): CourseStatusInterface {
    $course_status = $this->get('course_status')->entity;
    \assert($course_status instanceof CourseStatusInterface);
    return $course_status;
  }

  /**
   * {@inheritdoc}
   */
  public function getAnswerForm(): array {
    return $this->getLesson()->getLessonHandlerService()->getAnswerForm($this);
  }

  /**
   * {@inheritdoc}
   */
  public function buildResults(array &$element): void {
    $this->getLesson()->getLessonHandlerService()->buildResults($element, $this);
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['course_status'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(\t('Course status'))
      ->setDescription(\t('The learning path status this lesson status belongs to.'))
      ->setSetting('target_type', 'lms_course_status');

    // Legacy field.
    // @todo Remove in the next major.
    $fields['lesson'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(\t('Lesson (deprecated)'))
      ->setDescription(\t('The Lesson of this status (deprecated).'))
      ->setSetting('target_type', 'lms_lesson');

    $fields[self::LESSON_FIELD] = BaseFieldDefinition::create('lms_revision_reference')
      ->setLabel(\t('Lesson'))
      ->setDescription(\t('The Lesson of this status.'))
      ->setSetting('target_type', 'lms_lesson');

    $fields['score'] = BaseFieldDefinition::create('integer')
      ->setLabel(\t('Score'))
      ->setDescription(\t('The score the user obtained for this Lesson (percents).'));

    $fields['required_score'] = BaseFieldDefinition::create('integer')
      ->setLabel(\t('Required score'))
      ->setDescription(\t('Score required to complete this lesson (percents).'));

    $fields['mandatory'] = BaseFieldDefinition::create('boolean')
      ->setLabel(\t('Mandatory'))
      ->setDescription(\t('Was the referenced lesson mandatory when started?'));

    $fields['max_score'] = BaseFieldDefinition::create('integer')
      ->setLabel(\t('Maximum Score'))
      ->setDescription(\t('The maximum score that can be obtained for this Lesson (points).'));

    $fields['given_answers'] = BaseFieldDefinition::create('integer')
      ->setLabel(\t('Given answer count'))
      ->setDescription(\t('How many answers were given.'));

    $fields['percent'] = BaseFieldDefinition::create('integer')
      ->setLabel(\t('Pass Percent'))
      ->setDescription(\t('Number of answered questions / number of total questions.'));

    // Legacy field.
    // @todo Remove in the next major.
    $fields['activities'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(\t('Activities for this lesson status'))
      ->setSetting('target_type', 'lms_activity')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $fields[self::ACTIVITIES_FIELD] = BaseFieldDefinition::create('lms_revision_reference')
      ->setLabel(\t('Activities for this lesson status'))
      ->setSetting('target_type', 'lms_activity')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $fields['current_activity'] = BaseFieldDefinition::create('integer')
      ->setLabel(\t('Current activity delta'))
      ->setDescription(\t('The delta of the current activity'));

    $fields['evaluated'] = BaseFieldDefinition::create('boolean')
      ->setLabel(\t('Evaluation status'))
      ->setDescription(\t('A boolean indicating whether the lesson status is evaluated.'))
      ->setDefaultValue(FALSE);

    $fields['started'] = BaseFieldDefinition::create('created')
      ->setLabel(\t('Started'))
      ->setDescription(\t('The time that the Lesson has started.'));

    $fields['finished'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(\t('Finished'))
      ->setDescription(\t('The time that the Lesson finished.'));

    return $fields;
  }

}
