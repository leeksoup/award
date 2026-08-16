<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\TrainingManager;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reset student progress action.
 */
#[Action(
  id: 'lms:reset_course_progress',
  label: new TranslatableMarkup('Reset course progress'),
  type: 'group_relationship',
)]
final class ResetCourseProgress extends ViewsBulkOperationsActionBase implements ContainerFactoryPluginInterface {

  private const QUEUE_NAME = 'lms_delete_entities';

  /**
   * Static storage.
   */
  private ?Course $course = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TrainingManager $trainingManager,
    protected readonly QueueFactory $queueFactory,
    protected readonly QueueWorkerManagerInterface $queueWorkerManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(EntityTypeManagerInterface::class),
      $container->get(TrainingManager::class),
      $container->get(QueueFactory::class),
      $container->get(QueueWorkerManagerInterface::class)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?EntityInterface $entity = NULL): TranslatableMarkup {
    if (!$entity instanceof GroupRelationshipInterface) {
      return $this->t('Wrong entity type');
    }

    $course = $this->getCourse();
    if ($course === NULL) {
      return $this->t('No Course context available');
    }

    $account = $entity->getEntity();
    if (!$account instanceof AccountInterface) {
      return $this->t('Not a membership group relation');
    }
    if ($this->trainingManager->loadCourseStatus($course, $account) === NULL) {
      return $this->t('Course not started yet, nothing to reset');
    }

    $this->trainingManager->resetTraining($course->id(), $entity->getEntityId());

    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $worker = $this->queueWorkerManager->createInstance(self::QUEUE_NAME);
    while (\is_object($item = $queue->claimItem(1))) {
      $worker->processItem($item->data);
    }

    return $this->t('Course reset');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $course = $this->getCourse();

    // If the current user can edit the course, it's ok to grant access
    // to reset the course progress for any student as well.
    return $course->access('update', $account, $return_as_object);
  }

  /**
   * Course getter.
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
