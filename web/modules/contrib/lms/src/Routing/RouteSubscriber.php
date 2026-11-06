<?php

declare(strict_types=1);

namespace Drupal\lms\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\lms\LmsCourseStorage;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * The constructor.
   */
  public function __construct(
    protected readonly LmsCourseStorage $lmsCourseStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    // Set some admin routes.
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

    // Restrict course routes to course bundles. This cannot be done in
    // the routing YAML file as course bundles are dynamic.
    $course_bundles = $this->lmsCourseStorage->getCourseBundleIds();
    foreach ([
      'lms.course.start',
      'lms.course.reset_test',
      'lms.group.answer_form',
      'lms.group.results',
      'lms.group.self_results',
    ] as $route_name) {
      $route = $collection->get($route_name);
      if ($route === NULL) {
        continue;
      }
      $parameters = $route->getOption('parameters');
      $parameters['group']['bundle'] = $course_bundles;
      $route->setOption('parameters', $parameters);
    }
  }

}
