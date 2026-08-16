<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lms\Entity\ActivityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a class to build a listing of Activity entities.
 */
final class ActivityListBuilder extends EntityListBuilder {

  /**
   * Static cache of activity type labels.
   */
  private array $typeLabels = [];

  /**
   * The bundle storage class.
   */
  private EntityStorageInterface $bundleStorage;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')
    );
  }

  /**
   * The obvious.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($entity_type, $entity_type_manager->getStorage($entity_type->id()));
    $this->bundleStorage = $entity_type_manager->getStorage($entity_type->getBundleEntityType());
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header = [
      'id' => $this->t('Activity ID'),
      'name' => $this->t('Name'),
      'type' => $this->t('Type'),
      'owner' => $this->t('Owner'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    \assert($entity instanceof ActivityInterface);
    $row = [
      'id' => $entity->id(),
      'name' => $entity->toLink(),
    ];

    $bundle_id = $entity->bundle();
    if (!\array_key_exists($bundle_id, $this->typeLabels)) {
      $bundle = $this->bundleStorage->load($bundle_id);
      if ($bundle === NULL) {
        $this->typeLabels[$bundle_id] = $this->t('* Not installed *');
      }
      else {
        $this->typeLabels[$bundle_id] = $bundle->label();
      }
    }
    $row['type'] = $this->typeLabels[$bundle_id];
    $row['owner'] = $entity->get('uid')->entity->toLink();
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    if ($entity->access('update')) {
      $operations['revisions'] = [
        'title' => $this->t('Revisions'),
        'weight' => 20,
        'url' => $entity->toUrl('version-history'),
      ];
    }

    return $operations;
  }

}
