<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\QueueWorker;

use Drupal\commerce_lms_entitlements\ClickBankManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Discovers recent ClickBank purchases and reversals missed by INS.
 *
 * @QueueWorker(id = "commerce_lms_clickbank_reconcile", title = @Translation("ClickBank reconciliation"), cron = {"time" = 30})
 */
final class ClickBankReconcileWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private ClickBankManager $clickBank) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('commerce_lms_entitlements.clickbank'));
  }

  public function processItem($data): void {
    $this->clickBank->reconcileRecent();
  }
}
