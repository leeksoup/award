<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\views\field;

use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\CourseStatus;
use Drupal\lms\TrainingManager;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field handler to take training link.
 *
 * @ingroup views_field_handlers
 */
#[ViewsField(id: 'lms_course_take_link')]
final class CourseTakeLink extends FieldPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly TrainingManager $trainingManager,
    protected readonly AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('lms.training_manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // Don't do anything with the query.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): string {
    $course = $this->getEntity($values);
    if (!$course instanceof Course) {
      return '';
    }

    $course_status = $this->trainingManager->loadCourseStatus($course, $this->currentUser, [
      'current' => TRUE,
    ]);
    if ($course_status === NULL) {
      $link_text = $this->t('Get Started');
    }
    elseif ($course_status->getStatus() === CourseStatus::STATUS_NEEDS_EVALUATION) {
      return (string) $this->t('Awaits grading..');
    }
    elseif ($course_status->getStatus() === CourseStatus::STATUS_PROGRESS) {
      $link_text = $this->t('Resume Training');
    }
    else {
      return '';
    }

    // Take the bundle and build the take link.
    return (string) Link::createFromRoute(
      $link_text,
      'lms.course.start',
      ['group' => $course->id()]
    )->toString();
  }

}
