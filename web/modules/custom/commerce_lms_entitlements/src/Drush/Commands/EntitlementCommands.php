<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Drush\Commands;

use Drupal\commerce_lms_entitlements\PayPalPlanCatalog;
use Drupal\Core\Database\Connection;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/** Read-only operations report for LMS entitlement staff. */
final class EntitlementCommands extends DrushCommands {
  public function __construct(
    private Connection $database,
    private PayPalPlanCatalog $planCatalog,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'commerce-lms-entitlements:audit', aliases: ['clea'])]
  #[CLI\Usage(name: 'drush commerce-lms-entitlements:audit', description: 'Report recovery work and validate configured live PayPal plans.')]
  public function audit(): void {
    $failed_events = $this->database->select('commerce_lms_entitlement_event', 'e')->condition('status', 'failed')->countQuery()->execute()->fetchField();
    $refunds = $this->database->select('commerce_lms_entitlement', 'e')->condition('guarantee_requested', 0, '>')->isNull('refund_id')->countQuery()->execute()->fetchField();
    $this->output()->writeln('Failed webhook events: ' . $failed_events);
    $this->output()->writeln('Guarantee refunds needing recovery: ' . $refunds);

    $invalid_plans = 0;
    $unconfigured_plans = 0;
    foreach ($this->planCatalog->auditLiveOffers() as $offer_id => $result) {
      if (!$result['configured']) {
        $unconfigured_plans++;
        $this->output()->writeln(sprintf('Live PayPal mapping not configured: %s', $offer_id));
        continue;
      }
      $errors = $result['errors'];
      if (!$errors) {
        $this->output()->writeln(sprintf('Live PayPal mapping valid: %s', $offer_id));
        continue;
      }
      $invalid_plans++;
      foreach ($errors as $error) {
        $this->output()->writeln(sprintf('Live PayPal mapping invalid [%s]: %s', $offer_id, $error));
      }
    }
    $this->output()->writeln('Unconfigured live PayPal mappings: ' . $unconfigured_plans);
    $this->output()->writeln('Invalid live PayPal mappings: ' . $invalid_plans);
  }
}
