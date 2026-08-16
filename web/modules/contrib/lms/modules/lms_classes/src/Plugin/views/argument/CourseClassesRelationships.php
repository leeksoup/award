<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Plugin\views\argument;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms_classes\ClassHelper;
use Drupal\views\Attribute\ViewsArgument;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter to relationships of course child classes.
 *
 * @ingroup views_argument_handlers
 */
#[ViewsArgument(id: 'course_classes_relationships')]
final class CourseClassesRelationships extends ArgumentPluginBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityStorageInterface $groupStorage,
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
      $container->get('entity_type.manager')->getStorage('group')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore missingType.parameter
   */
  public function query($group_by = FALSE): void {
    $this->ensureMyTable();
    $course = $this->groupStorage->load($this->argument);
    if (!$course instanceof Course) {
      return;
    }
    $class_ids = [];
    foreach (ClassHelper::getClasses($course) as $class) {
      $class_ids[$class->id()] = $class->id();
    }
    \assert($this->query instanceof Sql);
    if (\count($class_ids) === 0) {
      $this->query->addWhere('0', "$this->tableAlias.gid", '0');
      return;
    }

    $this->query->addWhere('0', "$this->tableAlias.gid", $class_ids, 'IN');
  }

}
