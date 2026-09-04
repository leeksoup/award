<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;

/** Expires paid-through cancelled entitlements when no webhook arrives at expiry.
 * @QueueWorker(id = "commerce_lms_entitlements_reconcile", title = @Translation("Commerce LMS entitlement reconciliation"), cron = {"time" = 30})
 */
final class ReconcileWorker extends QueueWorkerBase {
  public function processItem($data): void { \Drupal::service('commerce_lms_entitlements.manager')->reconcile(); }
}
