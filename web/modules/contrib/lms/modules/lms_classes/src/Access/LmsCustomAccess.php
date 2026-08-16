<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms_classes\ClassHelper;

/**
 * LMS custom access methods.
 */
final class LmsCustomAccess implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Access to add a student to a class.
   */
  public function addStudentAccess(Course $group): AccessResultInterface {
    $result = AccessResult::forbidden();
    foreach (ClassHelper::getClasses($group) as $class) {
      if ($class->hasPermission('administer members', $this->currentUser)) {
        $result = AccessResult::allowed();
      }
    }
    return $result->addCacheContexts(['user.group_permissions']);
  }

}
