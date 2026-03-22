<?php

namespace Drupal\anu_lms;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\node\NodeTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adapt node titles.
 */
class NodeTitle implements ContainerInjectionInterface {

  /**
   * AnuLMS settings service.
   *
   * @var \Drupal\anu_lms\Settings
   */
  protected Settings $settings;

  /**
   * Creates an object.
   *
   * @param \Drupal\anu_lms\Settings $settings
   *   Anu LMS Settings service.
   */
  public function __construct(Settings $settings,) {
    $this->settings = $settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('anu_lms.settings'),
    );
  }

  /**
   * The custom _title_callback for the node.add route.
   *
   * @param \Drupal\node\NodeTypeInterface $node_type
   *   The current node.
   *
   * @return string
   *   The page title.
   */
  public function label(NodeTypeInterface $node_type) {
    return t('Create @name', ['@name' => $this->settings->getEntityLabel($node_type->label())]);
  }

}
