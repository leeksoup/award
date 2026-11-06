<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Access;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\flexible_permissions\CalculatedPermissionsItem;
use Drupal\flexible_permissions\PermissionCalculatorBase;
use Drupal\flexible_permissions\RefinableCalculatedPermissionsInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\lms\LmsCourseStorage;

/**
 * Calculates inherited group permissions for an account.
 */
final class ClassPermissionCalculator extends PermissionCalculatorBase {

  /**
   * The constructor.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LmsCourseStorage $lmsCourseStorage,
  ) {}

  /**
   * Performance: find relationships using IDs only.
   *
   * @param array<int|string> $group_ids
   *   Course group IDs.
   *
   * @return array<\Drupal\group\Entity\GroupRelationshipInterface>
   *   Group relationships.
   */
  private function getClassRelationshipsByGroupIds(array $group_ids): array {
    $relationship_ids = $this->database
      // So that method exists.
      // @phpstan-ignore-next-line
      ->select($this->entityTypeManager->getStorage('group_relationship')->getDataTable(), 'd')
      ->fields('d', ['id'])
      ->condition('gid', $group_ids, 'IN')
      ->condition('plugin_id', 'lms_classes')
      ->execute()
      ->fetchCol();
    /** @var \Drupal\group\Entity\GroupRelationshipInterface[] */
    $relationships = $this->entityTypeManager->getStorage('group_relationship')->loadMultiple($relationship_ids);
    return $relationships;
  }

  /**
   * Get permissions mappings.
   *
   * @return array<string, array<string, string>>
   *   Array of mapped permissions for courses and classes.
   *   Each 2nd level key corresponds to the granted permission and value to
   *   the required permission. If the required permission is an empty string,
   *   the key permission will always be granted.
   */
  private function getPermissionMappings(ImmutableConfig $config): array {
    // @todo Move default mappings to config in the next minor / major.
    $mappings = [
      'class' => [
        // Course members should always be able to view classes.
        'view group' => '',
        'administer members' => 'add students',
        'view group_membership relationship' => 'view students',
      ],
      'course' => [
        // Class members should always be able to view the course.
        'view group' => '',
        // Class members should always be able to take the course.
        'take course' => '',
      ],
    ];

    foreach ($config->get('class_permission_mappings') as $class_permission => $course_permission) {
      $mappings['class'][$class_permission] = $course_permission;
    }
    foreach ($config->get('course_permission_mappings') as $course_permission => $class_permission) {
      $mappings['course'][$course_permission] = $class_permission;
    }

    return $mappings;
  }

  /**
   * {@inheritdoc}
   */
  public function calculatePermissions(AccountInterface $account, $scope) {
    $calculated_permissions = parent::calculatePermissions($account, $scope);
    \assert($calculated_permissions instanceof RefinableCalculatedPermissionsInterface);

    if ($scope !== PermissionScopeInterface::INDIVIDUAL_ID) {
      return $calculated_permissions;
    }

    $config = $this->configFactory->get('lms_classes.settings');
    $permission_mappings = $this->getPermissionMappings($config);
    $calculated_permissions->addCacheableDependency($config);

    // Unconditional permissions.
    $unconditional_permissions = ['class' => [], 'course' => []];
    foreach (\array_keys($unconditional_permissions) as $group_type) {
      foreach ($permission_mappings[$group_type] as $granted_permission => $required_permission) {
        if ($required_permission !== '') {
          continue;
        }
        $unconditional_permissions[$group_type][$granted_permission] = $granted_permission;
        unset($permission_mappings[$group_type][$granted_permission]);
      }
    }

    // The inherited permissions need to be recalculated whenever the user is
    // added to or removed from a group.
    $calculated_permissions->addCacheTags(['group_relationship_list:plugin:group_membership:entity:' . $account->id()]);

    // Grant permissions on classes based on course membership.
    $memberships = $this->lmsCourseStorage->loadGroupRelationshipsByCourseBundles([
      'plugin_id' => 'group_membership',
      'entity_id' => $account->id(),
    ]);
    $permissions_by_group_id = [];
    $class_permissions_by_course_id = [];
    /** @var \Drupal\group\Entity\GroupMembershipInterface $membership */
    foreach ($memberships as $membership) {
      $calculated_permissions->addCacheableDependency($membership);

      $class_permissions = $unconditional_permissions['class'];
      if (\count($permission_mappings['class']) !== 0) {
        foreach ($membership->getRoles(TRUE) as $role) {
          $calculated_permissions->addCacheableDependency($role);
          foreach ($permission_mappings['class'] as $class_permission => $course_permission) {
            if ($role->hasPermission($course_permission)) {
              $class_permissions[$class_permission] = $class_permission;
            }
          }
        }
      }

      $class_permissions_by_course_id[$membership->getGroupId()] = $class_permissions;
    }
    if ($class_permissions_by_course_id !== []) {
      /** @var \Drupal\group\Entity\GroupRelationshipInterface $relationship */
      foreach ($this->getClassRelationshipsByGroupIds(\array_keys($class_permissions_by_course_id)) as $relationship) {
        $calculated_permissions->addCacheableDependency($relationship);

        $class_id = $relationship->getEntityId();
        if (!\array_key_exists($class_id, $permissions_by_group_id)) {
          $permissions_by_group_id[$class_id] = [];
        }
        // Merge permissions in case a user is in two or more classes of
        // the same course so nothing is lost.
        $permissions_by_group_id[$class_id] += $class_permissions_by_course_id[$relationship->getGroupId()];
      }
    }
    foreach ($permissions_by_group_id as $class_id => $class_permissions) {
      $calculated_permissions->addItem(new CalculatedPermissionsItem(
        $scope,
        $class_id,
        $class_permissions,
        FALSE,
      ));
    }

    // Grant permissions on courses based on class memberships.
    $permissions_by_group_id = [];
    $memberships = $this->entityTypeManager->getStorage('group_relationship')->loadByProperties([
      'plugin_id' => 'group_membership',
      'entity_id' => $account->id(),
      'group_type' => 'lms_class',
    ]);
    $course_permissions_by_class_id = [];
    /** @var \Drupal\group\Entity\GroupMembershipInterface $membership */
    foreach ($memberships as $membership) {
      $calculated_permissions->addCacheableDependency($membership);

      $course_permissions = $unconditional_permissions['course'];
      if (\count($permission_mappings['course']) !== 0) {
        foreach ($membership->getRoles(TRUE) as $role) {
          $calculated_permissions->addCacheableDependency($role);
          foreach ($permission_mappings['course'] as $course_permission => $class_permission) {
            if ($role->hasPermission($class_permission)) {
              $course_permissions[$course_permission] = $course_permission;
            }
          }
        }
      }

      $course_permissions_by_class_id[$membership->getGroupId()] = $course_permissions;
    }
    if ($course_permissions_by_class_id !== []) {
      $course_relationships = $this->lmsCourseStorage->loadGroupRelationshipsByCourseBundles([
        'plugin_id' => 'lms_classes',
        'entity_id' => \array_keys($course_permissions_by_class_id),
      ]);
      /** @var \Drupal\group\Entity\GroupRelationshipInterface $relationship */
      foreach ($course_relationships as $relationship) {
        $calculated_permissions->addCacheableDependency($relationship);
        $course_id = $relationship->getGroupId();
        if (!\array_key_exists($course_id, $permissions_by_group_id)) {
          $permissions_by_group_id[$course_id] = [];
        }
        // Merge permissions in case a user is in two or more classes of
        // the same course so nothing is lost.
        $permissions_by_group_id[$course_id] += $course_permissions_by_class_id[$relationship->getEntityId()];
      }
    }

    foreach ($permissions_by_group_id as $course_id => $course_permissions) {
      $calculated_permissions->addItem(new CalculatedPermissionsItem(
        $scope,
        $course_id,
        $course_permissions,
        FALSE,
      ));
    }

    return $calculated_permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function getPersistentCacheContexts($scope) {
    if ($scope === PermissionScopeInterface::INDIVIDUAL_ID) {
      return ['user'];
    }
    return [];
  }

}
