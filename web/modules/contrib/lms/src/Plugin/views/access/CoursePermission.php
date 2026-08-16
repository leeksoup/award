<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\views\access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Plugin\views\access\GroupPermission;
use Drupal\views\Attribute\ViewsAccess;
use Symfony\Component\Routing\Route;

/**
 * Just so we don't have 2 additional patches for core and group.
 *
 * @todo Track https://www.drupal.org/project/drupal/issues/2778345
 *   - alterRouteDefinitions() method.
 * @todo Track https://www.drupal.org/project/group/issues/3020883
 *   - access() method.
 *
 * @ingroup views_access_plugins
 */
#[ViewsAccess(
  id: 'lms_course_permission',
  title: new TranslatableMarkup('Course permission'),
  help: new TranslatableMarkup('Access will be granted to users with the specified course permission.'),
)]
final class CoursePermission extends GroupPermission {

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    if ($this->group === NULL) {
      return TRUE;
    }
    return parent::access($account);
  }

  /**
   * {@inheritdoc}
   */
  public function alterRouteDefinition(Route $route): void {
    parent::alterRouteDefinition($route);
    $parameters = $route->getOption('parameters');
    if (!\array_key_exists('group', $parameters)) {
      return;
    }
    $parameters['group']['bundle'] = ['lms_course'];
    $route->setOption('parameters', $parameters);
  }

}
