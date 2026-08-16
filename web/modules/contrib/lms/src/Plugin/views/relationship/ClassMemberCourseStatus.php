<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\views\relationship;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\views\Attribute\ViewsRelationship;
use Drupal\views\Plugin\ViewsHandlerManager;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\Plugin\views\relationship\RelationshipPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A relationship handler for group member Course Status.
 *
 * @ingroup views_relationship_handlers
 */
#[ViewsRelationship(id: 'class_member_to_course_status')]
final class ClassMemberCourseStatus extends RelationshipPluginBase {

  /**
   * The constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ViewsHandlerManager $joinManager,
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
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.views.join')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $this->ensureMyTable();

    // Build the join configuration.
    $configuration = [
      'left_table' => $this->table,
      'left_field' => 'entity_id',
      'table' => $this->definition['base'],
      'field' => 'uid',
      'operator' => '=',
      'extra' => [
        0 => [
          'field' => 'current',
          'value' => 1,
        ],
      ],
    ];

    // Get the current Course ID from View argument. If not there, we
    // don't have enough data to join.
    $course = NULL;
    foreach ($this->view->args as $arg) {
      if (!\is_numeric($arg)) {
        continue;
      }
      $course = $this->entityTypeManager->getStorage('group')->load($arg);
      if ($course instanceof Course) {
        break;
      }
    }

    if ($course instanceof Course) {
      $configuration['extra'][] = [
        'field' => CourseStatusInterface::COURSE_FIELD . '__target_id',
        'value' => $course->id(),
      ];
    }

    // Change the join to INNER if the relationship is required.
    if (\array_key_exists('required', $this->options) && (bool) $this->options['required'] === TRUE) {
      $configuration['type'] = 'INNER';
    }

    $join = $this->joinManager->createInstance('standard', $configuration);

    // Add the join using a more verbose alias.
    $alias = $this->definition['base'] . '_' . $this->table;
    \assert($this->query instanceof Sql);
    $this->alias = $this->query->addRelationship($alias, $join, $this->definition['base'], $this->relationship);
  }

}
