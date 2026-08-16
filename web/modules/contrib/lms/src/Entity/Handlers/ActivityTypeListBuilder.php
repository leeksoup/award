<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\lms\ActivityAnswerManager;
use Drupal\lms\Entity\ActivityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a listing of Activity type entities.
 */
final class ActivityTypeListBuilder extends ConfigEntityListBuilder {

  /**
   * Existing plugin definitions.
   */
  private array $definitions;

  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $entity_storage, protected ActivityAnswerManager $activityAnswerManager) {
    parent::__construct($entity_type, $entity_storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('plugin.manager.activity_answer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    $this->definitions = $this->activityAnswerManager->getDefinitions();
    return parent::load();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header = [
      'id' => $this->t('ID'),
      'label' => $this->t('Activity type'),
      'plugin' => $this->t('Plugin'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    \assert($entity instanceof ActivityTypeInterface);
    $row = [
      'id' => $entity->id(),
      'label' => $entity->label(),
      'plugin' => $this->definitions[$entity->getPluginId()]['name'] ?? $this->t('Missing plugin'),
    ];
    return $row + parent::buildRow($entity);
  }

}
