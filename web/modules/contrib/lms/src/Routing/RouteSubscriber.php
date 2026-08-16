<?php

declare(strict_types=1);

namespace Drupal\lms\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    // Set some additional properties on LMS routes.
    if (($route = $collection->get('view.lms_course_students.page')) !== NULL) {
      $route->setOption('_admin_route', TRUE);
      $parameters = $route->getOption('parameters');
      $parameters['group']['bundle'] = ['lms_course'];
      $route->setOption('parameters', $parameters);
    }

    // Set some more admin routes.
    foreach ([
      'view.subgroups.page_1',
      'view.group_members.page_1',
      'view.group_pending_members.page_1',
      'entity.group.version_history',
    ] as $route_name) {
      $route = $collection->get($route_name);
      if ($route === NULL) {
        continue;
      }
      $route->setOption('_admin_route', TRUE);
    }
  }

}
