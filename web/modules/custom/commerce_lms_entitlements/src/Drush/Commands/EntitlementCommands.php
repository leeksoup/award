<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/** Read-only operations report for LMS entitlement staff. */
final class EntitlementCommands extends DrushCommands {
  public function __construct(private Connection $database) { parent::__construct(); }

  #[CLI\Command(name: 'commerce-lms-entitlements:audit', aliases: ['clea'])]
  #[CLI\Usage(name: 'drush commerce-lms-entitlements:audit', description: 'Report failed webhooks and guarantee refund recovery work.')]
  public function audit(): void {
    $failed_events = $this->database->select('commerce_lms_entitlement_event', 'e')->condition('status', 'failed')->countQuery()->execute()->fetchField();
    $refunds = $this->database->select('commerce_lms_entitlement', 'e')->condition('guarantee_requested', 0, '>')->isNull('refund_id')->countQuery()->execute()->fetchField();
    $this->output()->writeln('Failed webhook events: ' . $failed_events);
    $this->output()->writeln('Guarantee refunds needing recovery: ' . $refunds);
  }
}
