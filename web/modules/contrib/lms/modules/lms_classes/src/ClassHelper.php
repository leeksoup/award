<?php

declare(strict_types=1);

namespace Drupal\lms_classes;

use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;

/**
 * Class helper methods.
 */
final class ClassHelper {

  /**
   * Get classes of a given course.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Class groups array.
   */
  public static function getClasses(Course $course): array {
    $classes = [];
    $relationships = $course->getRelationships('lms_classes');
    foreach ($relationships as $relationship) {
      $class = $relationship->getEntity();
      \assert($class instanceof GroupInterface);
      $classes[] = $class;
    }
    return $classes;
  }

  /**
   * Class membership checker.
   *
   * Checks if the given account is a member of any of the classes of this
   * learning path.
   */
  public static function isClassMember(AccountInterface $account, Course $course, ?RefinableCacheableDependencyInterface $cacheable_metadata = NULL): bool {
    $classes = self::getClasses($course);
    foreach ($classes as $class) {
      $member = $class->getMember($account);
      if ($member !== FALSE) {
        if ($cacheable_metadata !== NULL) {
          $cacheable_metadata->addCacheableDependency($member);
        }
        // If there is more than one membership this ignores the cacheable
        // metadata of the other membership, but that can only result in two
        // memberships going down to one.
        return TRUE;
      }
    }
    if ($cacheable_metadata !== NULL) {
      // Ensure invalidation whenever a member is added or removed from a class
      // on this course.
      foreach ($classes as $class) {
        $cacheable_metadata->addCacheTags(['group_relationship_list:plugin:group_membership:group:' . $class->id()]);
      }
      // And also when classes are added or removed from the course.
      $cacheable_metadata->addCacheTags(['group_relationship_list:plugin:lms_class:group:' . $course->id()]);
    }

    return FALSE;
  }

}
