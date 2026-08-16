<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Menu\LocalTask;

use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\LessonInterface;

/**
 * Provides local task for course activity.
 */
final class CourseActivityTask extends LocalTaskDefault {

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters(RouteMatchInterface $route_match): array {
    $group = $route_match->getParameter('group');
    $lesson_delta = $route_match->getParameter('lesson_delta');
    $activity_delta = $route_match->getParameter('activity_delta');

    if ($activity_delta === NULL) {
      $activity_delta = 0;
    }

    if ($group instanceof Course) {
      $lesson = $group->getLesson((int) $lesson_delta);
      if ($lesson instanceof LessonInterface) {
        $activity_items = $lesson->get(LessonInterface::ACTIVITIES);
        if ($activity_items->offsetExists((int) $activity_delta)) {
          $activity = $activity_items->get((int) $activity_delta)->get('entity')->getValue();
          if ($activity instanceof ActivityInterface) {
            return ['lms_activity' => $activity->id()];
          }
        }
      }
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getOptions(RouteMatchInterface $route_match): array {
    $options = parent::getOptions($route_match);

    // Append the destination so saving the backend form returns to the course.
    $options['query']['destination'] = Url::fromRoute('<current>')->toString();

    return $options;
  }

}
