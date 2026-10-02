<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Drush\Commands;

use Drupal\commerce_lms_entitlements\ClickBankManager;
use Drupal\Core\Database\Connection;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/** Staff audit and recovery commands for the ClickBank pilot. */
final class ClickBankCommands extends DrushCommands {
  public function __construct(private Connection $database, private ClickBankManager $clickBank) {
    parent::__construct();
  }

  #[CLI\Command(name: 'commerce-lms-entitlements:clickbank-audit')]
  public function audit(): void {
    if (!$this->database->schema()->tableExists('commerce_lms_clickbank_purchase')) {
      $this->output()->writeln('Run database updates before auditing ClickBank purchases.');
      return;
    }
    foreach ($this->database->select('commerce_lms_clickbank_purchase', 'p')->fields('p', ['receipt', 'status', 'reason', 'eid'])->orderBy('changed', 'DESC')->range(0, 100)->execute() as $row) {
      $this->output()->writeln(sprintf('%s: %s; entitlement=%s; reason=%s', $row->receipt, $row->status, $row->eid ?? '-', $row->reason ?? '-'));
    }
    $failed = $this->database->select('commerce_lms_clickbank_event', 'e')->condition('status', ['failed', 'review'], 'IN')->countQuery()->execute()->fetchField();
    $this->output()->writeln('Failed/review INS events: ' . $failed);
  }

  #[CLI\Command(name: 'commerce-lms-entitlements:clickbank-retry')]
  #[CLI\Argument(name: 'receipt', description: 'Exact ClickBank receipt ID.')]
  public function retry(string $receipt): void {
    $this->clickBank->reconcileReceipt($receipt);
    $this->output()->writeln('Reconciled ClickBank receipt ' . $receipt);
  }

  #[CLI\Command(name: 'commerce-lms-entitlements:clickbank-reconcile')]
  #[CLI\Option(name: 'days', description: 'Number of recent days to reconcile (1-30).')]
  public function reconcile(array $options = ['days' => 3]): void {
    $this->clickBank->reconcileRecent((int) ($options['days'] ?? 3));
    $this->output()->writeln('Queued recent ClickBank receipts for verification.');
  }
}
