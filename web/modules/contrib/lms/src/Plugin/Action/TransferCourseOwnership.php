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
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\LessonInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Transfers ownership of a course, its lessons and activities to a new user.
 */
#[Action(
  id: 'lms:transfer_course_ownership',
  label: new TranslatableMarkup('Transfer course ownership'),
  type: 'group',
)]
final class TransferCourseOwnership extends ViewsBulkOperationsActionBase implements ContainerFactoryPluginInterface, PluginFormInterface {

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
    if (!$entity instanceof Course) {
      return $this->t('Skipped: not an lms_course group.');
    }

    $new_owner_id = (int) $this->configuration['new_owner'];
    if ($new_owner_id === 0) {
      return $this->t('Skipped: no owner selected.');
    }

    $entity->setOwnerId($new_owner_id);
    $entity->save();

    foreach ($entity->get(Course::LESSONS) as $lesson_item) {
      /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem<\Drupal\lms\Entity\LessonInterface> $lesson_item */
      $lesson = $lesson_item->entity;
      if (!$lesson instanceof LessonInterface) {
        continue;
      }
      $lesson->setOwnerId($new_owner_id);
      $lesson->save();

      foreach ($lesson->get(LessonInterface::ACTIVITIES) as $activity_item) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem<\Drupal\lms\Entity\ActivityInterface> $activity_item */
        $activity = $activity_item->entity;
        if (!$activity instanceof ActivityInterface) {
          continue;
        }
        $activity->setOwnerId($new_owner_id);
        $activity->save();
      }
    }

    $new_owner = $this->entityTypeManager->getStorage('user')->load($new_owner_id);
    return $this->t('Ownership transferred to @name.', ['@name' => $new_owner?->label() ?? $new_owner_id]);
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
      '#description' => $this->t('The user who will become the new owner of the selected courses, their lessons and activities.'),
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
    return $object->access('update', $account, $return_as_object);
  }

}
