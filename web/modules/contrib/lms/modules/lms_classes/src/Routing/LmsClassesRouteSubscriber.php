<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\lms\LmsCourseStorage;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
final class LmsClassesRouteSubscriber extends RouteSubscriberBase {

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
    $course_bundles = $this->lmsCourseStorage->getCourseBundleIds();

    // Students view alters.
    if (($route = $collection->get('view.lms_course_students.page')) !== NULL) {
      $route->setOption('_admin_route', TRUE);
      $parameters = $route->getOption('parameters');
      $parameters['group']['bundle'] = $course_bundles;
      $route->setOption('parameters', $parameters);
    }

    // Restrict the add student route to course bundles.
    if (($route = $collection->get('lms_classes.group.add_student')) !== NULL) {
      $parameters = $route->getOption('parameters');
      $parameters['group']['bundle'] = $course_bundles;
      $route->setOption('parameters', $parameters);
    }
  }

}
