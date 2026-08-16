<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\views\field;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\lms\Entity\CourseStatus as CourseStatusEntity;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * LMS Course user status field.
 */
#[ViewsField(id: 'lms_student_course_status')]
final class CourseStatus extends FieldPluginBase {

  /**
   * The current user.
   */
  private AccountInterface $currentUser;

  /**
   * Injects services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The dependency injection container.
   */
  public function injectServices(ContainerInterface $container): void {
    $this->currentUser = $container->get('current_user');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->injectServices($container);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): MarkupInterface {
    // Determine the course status text and class.
    $status = $this->getValue($values);
    if ($status === NULL) {
      $status_text = $this->t('Not started');
      $status_class = 'not-started';
    }
    else {
      $status_text = CourseStatusEntity::getStatusText($status);
      $status_class = \strtr($status, ' ', '-');
    }

    // Get the course status entity if available.
    $course_status = $values->_relationship_entities['course_status'] ?? NULL;
    if ($course_status === NULL) {
      $course_status = $values->_entity;
    }

    // Generate URL to results if applicable.
    $url = '';
    if ($course_status instanceof CourseStatusEntity) {
      $route_parameters = [
        'group' => $course_status->getCourseId(),
      ];

      if ($course_status->getUserId() === (string) $this->currentUser->id()) {
        $route = 'lms.group.self_results';
      }
      else {
        $route = 'lms.group.results';
        $route_parameters['user'] = $course_status->getUserId();
      }

      $url = Url::fromRoute($route, $route_parameters)->toString();
    }

    // Create SDC component render array.
    $build = [
      '#type' => 'component',
      '#component' => 'lms:course_status',
      '#props' => [
        'status_text' => $status_text,
        'status_class' => $status_class,
        'url' => $url,
      ],
    ];

    // Render the component.
    $output = (string) $this->getRenderer()->render($build);
    return Markup::create($output);
  }

}
