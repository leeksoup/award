<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker deleting queued entities.
 */
#[QueueWorker(
  id: 'lms_delete_entities',
  title: new TranslatableMarkup('Delete entities'),
  cron: ['time' => 30],
)]
final class DeleteEntitiesWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    \assert(\is_array($data));
    \assert(\array_key_exists('entity_type', $data));
    \assert(\array_key_exists('ids', $data) && \is_array($data['ids']));
    $storage = $this->entityTypeManager->getStorage($data['entity_type']);
    $storage->delete($storage->loadMultiple($data['ids']));
  }

}
