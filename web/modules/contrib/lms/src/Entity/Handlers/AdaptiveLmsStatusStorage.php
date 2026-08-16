<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Storage handler for LMS progress entities supporting anonymous users.
 *
 * Authenticated users fall through to the standard SQL backend.
 * Anonymous users have their progress serialized into the private tempstore
 * (session-scoped), under a single collection keyed by entity type ID.
 */
class AdaptiveLmsStatusStorage extends SqlContentEntityStorage {

  private const TEMPSTORE_NAME = 'lms_entity_storage';

  /**
   * The private tempstore factory.
   */
  protected PrivateTempStoreFactory $tempStoreFactory;

  /**
   * The current user account.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Prototype query instance cloned for each getQuery() call.
   */
  private TempStoreEntityQuery $tempStoreEntityQuery;

  /**
   * In-memory cache of all entity field-data arrays for this entity type.
   *
   * Keyed by entity ID. NULL until first load from tempstore.
   *
   * @var array<int, array<string, mixed>>|null
   */
  private ?array $entities = NULL;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $instance = parent::createInstance($container, $entity_type);
    $instance->tempStoreFactory = $container->get('tempstore.private');
    $instance->currentUser = $container->get('current_user');
    $instance->tempStoreEntityQuery = $container->get(TempStoreEntityQuery::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function load($id) {
    if ($this->shouldUseTempStore()) {
      return $this->loadMultiple([(int) $id])[(int) $id] ?? NULL;
    }
    return parent::load($id);
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(?array $ids = NULL): array {
    if (!$this->shouldUseTempStore()) {
      return parent::loadMultiple($ids);
    }

    $all = $this->getEntitiesArray();
    $subset = $ids === NULL ? $all : \array_intersect_key($all, \array_flip(\array_map('intval', $ids)));

    $result = [];
    foreach ($subset as $id => $data) {
      $result[$id] = $this->createEntityFromData($data);
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function loadByProperties(array $values = []): array {
    if (!$this->shouldUseTempStore()) {
      return parent::loadByProperties($values);
    }

    $ids = [];
    foreach ($this->getEntitiesArray() as $id => $data) {
      if ($this->matchesProperties($data, $values)) {
        $ids[] = $id;
      }
    }

    return $this->loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   *
   * Skip module hooks (e.g. comment_entity_insert) for tempstore entities.
   * Those hooks assume SQL-backed IDs and fail with tempstore IDs.
   * Entity-level postSave() still fires for internal entity state management.
   */
  protected function doPostSave(EntityInterface $entity, $update): void {
    if ($this->shouldUseTempStore()) {
      $this->resetCache([$entity->id()]);
      $entity->enforceIsNew(FALSE);
      $entity->postSave($this, $update);
      $entity->setOriginalId($entity->id());
      return;
    }
    parent::doPostSave($entity, $update);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuery($conjunction = 'AND') {
    if ($this->shouldUseTempStore()) {
      $query = clone $this->tempStoreEntityQuery;
      $query->configure(
        fn() => $this->getEntitiesArray(),
        fn($data, $field, $value, $op) => $this->matchesCondition($data, $field, $value, $op),
      );
      // @phpstan-ignore return.type
      return $query;
    }
    return parent::getQuery($conjunction);
  }

  /**
   * {@inheritdoc}
   */
  protected function doSave($id, EntityInterface $entity) {
    if ($this->shouldUseTempStore()) {
      \assert($entity instanceof ContentEntityInterface);
      return $this->doSaveToTempStore($entity);
    }
    return parent::doSave($id, $entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function doDelete($entities): void {
    if ($this->shouldUseTempStore()) {
      $this->doDeleteFromTempStore($entities);
      return;
    }
    parent::doDelete($entities);
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns TRUE when anonymous tempstore should be used instead of SQL.
   *
   * CLI contexts (Kernel tests, drush) have no HTTP session, so anonymous
   * entities there must go to SQL — only real browser sessions use tempstore.
   */
  private function shouldUseTempStore(): bool {
    return $this->currentUser->isAnonymous() && \PHP_SAPI !== 'cli';
  }

  /**
   * Returns the shared private tempstore collection.
   */
  private function getTempStore(): PrivateTempStore {
    return $this->tempStoreFactory->get(self::TEMPSTORE_NAME);
  }

  /**
   * Returns (and lazily caches) all entity field-data arrays for this type.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getEntitiesArray(): array {
    if ($this->entities === NULL) {
      $this->entities = $this->getTempStore()->get($this->entityTypeId) ?? [];
    }
    return $this->entities;
  }

  /**
   * Instantiates a content entity from raw toArray() field data.
   */
  private function createEntityFromData(array $data): ContentEntityInterface {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->entityTypeManager
      ->getStorage($this->entityTypeId)
      ->create($data);
    $entity->enforceIsNew(FALSE);
    return $entity;
  }

  /**
   * Writes an entity to the in-memory cache and tempstore.
   *
   * Answers are auto-marked as evaluated so anonymous courses resolve to
   * passed/failed rather than waiting for teacher evaluation.
   */
  private function doSaveToTempStore(ContentEntityInterface $entity): int {
    if ($this->entityTypeId === 'lms_answer' && $entity->hasField('evaluated')) {
      $entity->set('evaluated', TRUE);
    }

    $isNew = $entity->isNew();
    $entities = $this->getEntitiesArray();

    if ($isNew) {
      $nextId = \count($entities) + 1;
      $entity->set($this->entityType->getKey('id'), (string) $nextId);
    }

    $entityId = (int) $entity->id();
    $entities[$entityId] = $this->toStorableArray($entity->toArray());
    $this->entities = $entities;
    $this->getTempStore()->set($this->entityTypeId, $entities);

    return $isNew ? SAVED_NEW : SAVED_UPDATED;
  }

  /**
   * Removes entities from the in-memory cache and tempstore.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   */
  private function doDeleteFromTempStore(array $entities): void {
    $all = $this->getEntitiesArray();

    foreach ($entities as $entity) {
      unset($all[(int) $entity->id()]);
    }

    $this->entities = $all;
    $this->getTempStore()->set($this->entityTypeId, $all);
  }

  /**
   * Wipes all tempstore progress for this entity type in the current session.
   */
  public function clearAnonymousProgress(): void {
    if (!$this->shouldUseTempStore()) {
      return;
    }

    $this->entities = [];
    $this->getTempStore()->delete($this->entityTypeId);
  }

  /**
   * Strips PHP entity objects from entity reference field values.
   *
   * EntityReferenceItem::getValue() includes the live entity object under the
   * 'entity' key. Storing that in tempstore corrupts the field on reload.
   */
  private function toStorableArray(array $data): array {
    foreach ($data as &$fieldValues) {
      if (\is_array($fieldValues)) {
        foreach ($fieldValues as &$itemValue) {
          if (\is_array($itemValue)) {
            unset($itemValue['entity']);
          }
        }
      }
    }
    return $data;
  }

  /**
   * TRUE when all $conditions match fields in the raw entity data array.
   *
   * @param array<string, mixed> $conditions
   */
  private function matchesProperties(array $data, array $conditions): bool {
    foreach ($conditions as $fieldName => $expectedValue) {
      if (!$this->matchesCondition($data, $fieldName, $expectedValue, '=')) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Returns TRUE when a single condition matches a field in raw entity data.
   *
   * Reads the first delta's main scalar value ('value' or 'target_id').
   * Uses loose comparison to mirror Drupal entity query behavior — boolean
   * fields serialize as int 1/0 but callers pass TRUE/FALSE, and entity
   * reference target IDs may be int in toArray() but string from ->id().
   */
  private function matchesCondition(array $data, string $fieldName, mixed $expectedValue, string $operator): bool {
    if (!\array_key_exists($fieldName, $data)) {
      return FALSE;
    }

    $items = $data[$fieldName];
    if (\count($items) === 0) {
      return match($operator) {
        // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
        'IN' => \in_array(NULL, (array) $expectedValue, TRUE),
        // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
        default => $expectedValue == NULL,
      };
    }

    $actualValue = $items[0]['value'] ?? $items[0]['target_id'] ?? NULL;

    // phpcs:disable SlevomatCodingStandard.Operators.DisallowEqualOperators
    return match($operator) {
      'IN' => (static function () use ($actualValue, $expectedValue): bool {
        foreach ((array) $expectedValue as $candidate) {
          if ($actualValue == $candidate) {
            return TRUE;
          }
        }
        return FALSE;
      })(),
      '<>' => $actualValue != $expectedValue,
      default => $actualValue == $expectedValue,
    };
    // phpcs:enable SlevomatCodingStandard.Operators.DisallowEqualOperators
  }

}
