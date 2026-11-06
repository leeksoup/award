<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\lms\Entity\LessonInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Defines a class to build a listing of Lesson entities.
 */
final class LessonListBuilder extends EntityListBuilder {

  /**
   * Lesson bundles.
   */
  protected array $bundles;

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityTypeManagerInterface $entity_type_manager,
    protected PagerManagerInterface $pagerManager,
    protected RequestStack $requestStack,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {
    parent::__construct($entity_type, $entity_type_manager->getStorage($entity_type->id()));
    $this->bundles = $entityTypeBundleInfo->getBundleInfo($entity_type->id());
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get(EntityTypeManagerInterface::class),
      $container->get(PagerManagerInterface::class),
      $container->get(RequestStack::class),
      $container->get(EntityTypeBundleInfoInterface::class),
    );
  }

  /**
   * Returns lessons count.
   *
   * @return int
   *   The total amount of lesson entities.
   */
  private function getTotalCount(): int {
    return $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->count()
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE);
    if ($this->limit) {
      $page = $this->requestStack->getCurrentRequest()->query->get('page', 0);
      $limit = $this->limit;
      $start = $limit * $page;
      $query->range($start, $limit);
    }
    $query->sort($this->entityType->getKey('id'));
    $ids = $query->execute();

    return $ids;
  }

  /**
   * Renders the lesson entities.
   *
   * @return mixed[]
   *   A render array.
   */
  public function render(): array {
    $this->pagerManager->createPager($this->getTotalCount(), $this->limit);

    $build = parent::render();
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header = [
      'id' => $this->t('Lesson ID'),
      'name' => $this->t('Name'),
      'owner' => $this->t('Owner'),
    ];
    if (\count($this->bundles) > 1) {
      $header['lms_lesson_type'] = $this->t('Lesson type');
    }
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    \assert($entity instanceof LessonInterface);

    $row = [
      'id' => $entity->id(),
      'name' => $entity->toLink(),
      'owner' => $entity->get('uid')->entity->toLink(),
    ];

    if (\count($this->bundles) > 1) {
      $bundle = $entity->bundle();
      $row['lms_lesson_type'] = \array_key_exists($bundle, $this->bundles) ? $this->bundles[$bundle]['label'] : $this->t('Invalid');
    }

    // Change default edit link.
    $ops = parent::buildRow($entity);
    $ops['operations']['data']['#links']['edit']['url'] = new Url(
      'entity.lms_lesson.edit_form', [
        'lms_lesson' => $entity->id(),
      ],
      [
        'query' => ['destination' => $this->requestStack->getCurrentRequest()->getRequestUri()],
      ],
    );

    return $row + $ops;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL) {
    $operations = parent::getDefaultOperations($entity, $cacheability);
    $update_access = $entity->access('update', return_as_object: TRUE);
    $cacheability?->addCacheableDependency($update_access);
    if ($update_access->isAllowed()) {
      $operations['revisions'] = [
        'title' => $this->t('Revisions'),
        'weight' => 20,
        'url' => $entity->toUrl('version-history'),
      ];
    }

    return $operations;
  }

}
