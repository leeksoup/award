<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\CourseNavBlockBuilder;
use Drupal\lms\Entity\Bundle\CourseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a course navigation block.
 */
#[Block(
  id: 'course_steps_block',
  admin_label: new TranslatableMarkup('Course navigation'),
  context_definitions: [
    'user' => new EntityContextDefinition('entity:user', new TranslatableMarkup('User')),
    'group' => new EntityContextDefinition('entity:group', new TranslatableMarkup('Group')),
  ]
)]
final class CourseNavBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected CourseNavBlockBuilder $blockBuilder,
    protected RouteMatchInterface $currentRouteMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(CourseNavBlockBuilder::class),
      $container->get(RouteMatchInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    // Available only on answer form routes.
    if ($this->currentRouteMatch->getRouteName() === 'lms.group.answer_form') {
      $result = AccessResult::allowed();
    }
    else {
      $result = AccessResult::forbidden();
    }
    return $result->addCacheContexts(['route.name']);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $group = $this->getContextValue('group');
    if (!$group instanceof CourseInterface) {
      return [];
    }
    $user = $this->getContextValue('user');
    return $this->blockBuilder->build($group, $user);
  }

}
