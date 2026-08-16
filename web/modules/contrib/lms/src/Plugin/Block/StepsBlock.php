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
use Drupal\lms\BlockBuilder;
use Drupal\lms\Entity\Bundle\Course;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a course navigation block.
 *
 * @todo Drop the legacy themes in favor of new, simple ones.
 */
#[Block(
  id: 'course_steps_block',
  admin_label: new TranslatableMarkup('Course navigation'),
  context_definitions: [
    'user' => new EntityContextDefinition('entity:user', new TranslatableMarkup('User')),
    'group' => new EntityContextDefinition('entity:group', new TranslatableMarkup('Group')),
  ]
)]
final class StepsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly BlockBuilder $blockBuilder,
    protected readonly RouteMatchInterface $currentRouteMatch,
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
      $container->get('lms.block_builder'),
      $container->get('current_route_match'),
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
    if (!$group instanceof Course) {
      return [];
    }
    $user = $this->getContextValue('user');
    return $this->blockBuilder->build($group, $user);
  }

}
