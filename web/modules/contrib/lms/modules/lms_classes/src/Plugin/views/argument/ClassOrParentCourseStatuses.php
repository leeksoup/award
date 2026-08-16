<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Plugin\views\argument;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\views\Attribute\ViewsArgument;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Classes supporting learning path status argument.
 *
 * Show only learning path statuses for all group classes or the current
 * class members.
 *
 * @ingroup views_argument_handlers
 */
#[ViewsArgument(id: 'class_parent_or_current_group')]
final class ClassOrParentCourseStatuses extends ArgumentPluginBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore missingType.parameter
   */
  public function query($group_by = FALSE): void {
    $this->ensureMyTable();
    $group = $this->entityTypeManager->getStorage('group')->load($this->argument);
    if (!$group instanceof GroupInterface) {
      return;
    }

    \assert($this->query instanceof Sql);

    if ($group instanceof Course) {
      $this->query->addWhere('0', "$this->tableAlias.gid", $this->argument, '=');
      return;
    }
    if ($group->bundle() !== 'lms_class') {
      return;
    }

    $course_ids = [];

    /** @var \Drupal\group\Entity\GroupRelationshipInterface[] */
    $course_relationships = $this->entityTypeManager->getStorage('group_relationship')->loadByProperties([
      'plugin_id' => 'lms_classes',
      'entity_id' => $group->id(),
      'group_type' => 'lms_course',
    ]);
    foreach ($course_relationships as $relationship) {
      $gid = $relationship->getGroupId();
      $course_ids[$gid] = $gid;
    }

    if (\count($course_ids) !== 0) {
      $this->query->addWhere('0', "$this->tableAlias.gid", $course_ids, 'IN');
    }
    $user_ids = [];
    foreach (GroupMembership::loadByGroup($group) as $membership) {
      $user_ids[] = $membership->getEntityId();
    }
    $this->query->addWhere('0', "$this->tableAlias.uid", $user_ids, 'IN');
  }

}
