<?php

declare(strict_types=1);

namespace Drupal\lms;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lms\Entity\Bundle\CourseInterface;

/**
 * Course bundle lookups.
 */
final class LmsCourseStorage implements CacheTagsInvalidatorInterface {

  /**
   * Static cache of course bundle IDs.
   *
   * @var string[]|null
   */
  private ?array $courseBundleIds = NULL;

  /**
   * The constructor.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    if (\in_array('entity_bundles', $tags, TRUE)) {
      $this->courseBundleIds = NULL;
    }
  }

  /**
   * Get all course bundle IDs that implement CourseInterface.
   *
   * @return string[]
   *   Array of group bundle IDs that implement CourseInterface.
   */
  public function getCourseBundleIds(): array {
    if ($this->courseBundleIds !== NULL) {
      return $this->courseBundleIds;
    }

    $this->courseBundleIds = [];
    foreach ($this->entityTypeBundleInfo->getBundleInfo('group') as $bundle_id => $bundle_info) {
      $class = $bundle_info['class'] ?? NULL;
      if ($class !== NULL && \is_a($class, CourseInterface::class, TRUE)) {
        $this->courseBundleIds[] = $bundle_id;
      }
    }

    return $this->courseBundleIds;
  }

  /**
   * Load group relationships filtered by course bundles.
   *
   * Wrapper around loadByProperties that filters to course bundles.
   *
   * @param array<string, mixed> $properties
   *   Properties to filter by (excluding group_type).
   *
   * @return array<\Drupal\group\Entity\GroupRelationshipInterface>
   *   Matching group relationships.
   */
  public function loadGroupRelationshipsByCourseBundles(array $properties): array {
    $properties['group_type'] = $this->getCourseBundleIds();
    // An empty IN condition is not valid SQL.
    if (\count($properties['group_type']) === 0) {
      return [];
    }
    /** @var array<\Drupal\group\Entity\GroupRelationshipInterface> */
    return $this->entityTypeManager->getStorage('group_relationship')->loadByProperties($properties);
  }

}
