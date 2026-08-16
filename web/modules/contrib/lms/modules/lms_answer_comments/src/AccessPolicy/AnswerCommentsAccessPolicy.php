<?php

declare(strict_types=1);

namespace Drupal\lms_answer_comments\AccessPolicy;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccessPolicyBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\CalculatedPermissionsItem;
use Drupal\Core\Session\RefinableCalculatedPermissionsInterface;
use Drupal\group\GroupMembership;
use Drupal\lms\Entity\AnswerInterface;

/**
 * Access policy for answer comments.
 */
final class AnswerCommentsAccessPolicy extends AccessPolicyBase {

  public function __construct(
    protected readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(string $scope): bool {
    return parent::applies($scope) ? $this->routeMatch->getRouteName() === 'lms.answer.details' : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function calculatePermissions(AccountInterface $account, string $scope): RefinableCalculatedPermissionsInterface {
    $calculated_permissions = parent::calculatePermissions($account, $scope);

    $lms_answer = $this->routeMatch->getParameter('lms_answer');
    if (!$lms_answer instanceof AnswerInterface) {
      return $calculated_permissions;
    }

    if ($account->id() === $lms_answer->getOwnerId()) {
      // We have a student.
      $calculated_permissions
        ->addItem(new CalculatedPermissionsItem(['access comments', 'post comments']))
        ->addCacheableDependency($lms_answer)
        ->addCacheableDependency($account);
      return $calculated_permissions;
    }

    $course = $lms_answer->getLessonStatus()->getCourseStatus()->getCourse();
    $membership = $course->getMember($account);
    if (!$membership instanceof GroupMembership) {
      return $calculated_permissions;
    }

    // We have a teacher.
    $calculated_permissions
      ->addItem(new CalculatedPermissionsItem(['access comments', 'post comments', 'edit own comments']))
      ->addCacheableDependency($membership)
      ->addCacheableDependency($account);

    return $calculated_permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function getPersistentCacheContexts(): array {
    return ['route', 'user'];
  }

}
