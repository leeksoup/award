<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Transfers ownership of results of a course.
 */
#[Action(
  id: 'lms:transfer_course_results',
  label: new TranslatableMarkup('Transfer course results'),
  type: 'group_relationship',
)]
final class TransferCourseResults extends ViewsBulkOperationsActionBase implements ContainerFactoryPluginInterface, PluginFormInterface {

  /**
   * Static cache for the course loaded from action context.
   */
  private ?Course $course = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?EntityInterface $entity = NULL): TranslatableMarkup {
    if (!$entity instanceof GroupRelationshipInterface) {
      return $this->t('Skipped: wrong entity type.');
    }

    $course = $this->getCourse();
    if ($course === NULL) {
      return $this->t('Skipped: no course context available.');
    }

    $account = $entity->getEntity();
    if (!$account instanceof AccountInterface) {
      return $this->t('Skipped: not a membership group relation.');
    }

    $new_owner_id = (int) $this->configuration['new_owner'];
    if ($new_owner_id === 0) {
      return $this->t('Skipped: no owner selected.');
    }

    $ids = $this->entityTypeManager
      ->getStorage('lms_course_status')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition(CourseStatusInterface::COURSE_FIELD, $course->id())
      ->condition('uid', $account->id())
      ->sort('started', 'DESC')
      ->range(0, 1)
      ->execute();

    if (\count($ids) === 0) {
      return $this->t('Skipped: no course status found.');
    }

    /** @var \Drupal\lms\Entity\CourseStatusInterface|null $course_status */
    $course_status = $this->entityTypeManager
      ->getStorage('lms_course_status')
      ->load(\reset($ids));

    if (!$course_status instanceof CourseStatusInterface) {
      return $this->t('Skipped: could not load course status.');
    }

    $conflict_ids = $this->entityTypeManager
      ->getStorage('lms_course_status')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition(CourseStatusInterface::COURSE_FIELD, $course->id())
      ->condition('uid', $new_owner_id)
      ->condition('finished', 0)
      ->condition('id', $course_status->id(), '<>')
      ->execute();

    if (\count($conflict_ids) > 0) {
      return $this->t('Skipped: the target user already has an in-progress status for this course.');
    }

    $course_status->set('uid', $new_owner_id);
    $course_status->save();

    $lesson_status_ids = $this->entityTypeManager
      ->getStorage('lms_lesson_status')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('course_status', $course_status->id())
      ->execute();

    if (\count($lesson_status_ids) > 0) {
      $lesson_statuses = $this->entityTypeManager
        ->getStorage('lms_lesson_status')
        ->loadMultiple($lesson_status_ids);

      foreach ($lesson_statuses as $lesson_status) {
        /** @var \Drupal\lms\Entity\LessonStatusInterface $lesson_status */

        $answer_ids = $this->entityTypeManager
          ->getStorage('lms_answer')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('lesson_status', $lesson_status->id())
          ->execute();

        if (\count($answer_ids) === 0) {
          continue;
        }

        $answers = $this->entityTypeManager
          ->getStorage('lms_answer')
          ->loadMultiple($answer_ids);

        foreach ($answers as $answer) {
          /** @var \Drupal\lms\Entity\AnswerInterface $answer */
          $answer->setOwnerId($new_owner_id);
          $answer->save();
        }
      }
    }

    $new_owner = $this->entityTypeManager->getStorage('user')->load($new_owner_id);
    return $this->t('Results transferred to @name.', ['@name' => $new_owner?->label() ?? $new_owner_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $default_user = NULL;
    if (($this->configuration['new_owner'] ?? '') !== '') {
      $default_user = $this->entityTypeManager->getStorage('user')->load($this->configuration['new_owner']);
    }

    $form['new_owner'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('New owner'),
      '#description' => $this->t('The user who will become the new owner of the selected course statuses and their answers.'),
      '#target_type' => 'user',
      '#required' => TRUE,
      '#default_value' => $default_user,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $course = $this->getCourse();
    if ($course === NULL) {
      return FALSE;
    }
    return $course->access('update', $account, $return_as_object);
  }

  /**
   * Course getter from action context arguments.
   */
  private function getCourse(): ?Course {
    if ($this->course !== NULL) {
      return $this->course;
    }

    $course = NULL;
    if (\array_key_exists(0, $this->context['arguments'])) {
      $course = $this->entityTypeManager->getStorage('group')->load($this->context['arguments'][0]);
    }
    if ($course instanceof Course) {
      $this->course = $course;
      return $course;
    }

    return NULL;
  }

}
