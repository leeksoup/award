<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\QueueWorker;

use Drupal\commerce_lms_entitlements\ClickBankManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rechecks one ClickBank receipt discovered by the Orders API list.
 *
 * @QueueWorker(id = "commerce_lms_clickbank_receipt", title = @Translation("ClickBank receipts"), cron = {"time" = 30})
 */
final class ClickBankReceiptWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private ClickBankManager $clickBank) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('commerce_lms_entitlements.clickbank'));
  }

  public function processItem($data): void {
    try {
      $this->clickBank->reconcileReceipt((string) $data['receipt']);
    }
    catch (\DomainException) {
      // A persisted review case (for example a missing learner intent) is
      // actionable by staff, not a queue item that should retry forever.
    }
  }
}
