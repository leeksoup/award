<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Drush\Commands;

use Drupal\commerce_lms_entitlements\Entity\LmsSubscriptionCampaign;
use Drupal\commerce_lms_entitlements\PayPalPlanCatalog;
use Drupal\commerce_lms_entitlements\PayPalSubscriptionOperations;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/** Read-only operations report for LMS entitlement staff. */
final class EntitlementCommands extends DrushCommands {
  public function __construct(
    private Connection $database,
    private PayPalPlanCatalog $planCatalog,
    private EntityTypeManagerInterface $entityTypeManager,
    private PayPalSubscriptionOperations $paypalOperations,
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
    if ($this->database->schema()->tableExists('commerce_lms_plan_change')) {
      $stalled = $this->database->select('commerce_lms_plan_change', 'p')
        ->condition('status', ['approval_pending', 'approved'], 'IN')
        ->condition('changed', time() - 172800, '<')
        ->countQuery()->execute()->fetchField();
      $this->output()->writeln('Tier changes stalled for more than 48 hours: ' . $stalled);
    }
    if ($this->database->schema()->tableExists('commerce_lms_vip_booking')) {
      $orphaned = 0;
      foreach ($this->database->select('commerce_lms_vip_booking', 'b')->fields('b', ['uid'])->distinct()->execute()->fetchCol() as $uid) {
        $active = $this->database->select('commerce_lms_entitlement', 'e')
          ->condition('learner_uid', $uid)
          ->condition('status', 'active')
          ->condition('vip_active', 1)
          ->countQuery()->execute()->fetchField();
        if (!$active) {
          $orphaned++;
        }
      }
      $this->output()->writeln('Learners with orphaned VIP bookings: ' . $orphaned);
    }
    $remote_plan_mismatches = 0;
    foreach ($this->database->select('commerce_lms_entitlement', 'e')->fields('e')->condition('purchase_type', 'recurring')->condition('status', ['pending', 'active', 'suspended'], 'IN')->isNotNull('paypal_subscription_id')->execute()->fetchAllAssoc('eid') as $entitlement) {
      try {
        $order = $this->entityTypeManager->getStorage('commerce_order')->load($entitlement->order_id);
        $gateway_id = (string) ($order?->get('payment_gateway')->target_id ?? '');
        $gateway = $gateway_id !== '' ? $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($gateway_id) : NULL;
        if (!$gateway) {
          throw new \RuntimeException('missing payment gateway');
        }
        $remote = $this->paypalOperations->fetchSubscription($gateway, $entitlement->paypal_subscription_id);
        $allowed = array_filter([(string) $entitlement->paypal_plan_id]);
        if ($this->database->schema()->tableExists('commerce_lms_plan_change')) {
          $pending = $this->database->select('commerce_lms_plan_change', 'p')->fields('p', ['target_plan_id'])->condition('eid', $entitlement->eid)->condition('status', ['approval_pending', 'approved'], 'IN')->execute()->fetchField();
          if ($pending) {
            $allowed[] = (string) $pending;
          }
        }
        if (!in_array((string) ($remote['plan_id'] ?? ''), $allowed, TRUE)) {
          $remote_plan_mismatches++;
          $this->output()->writeln(sprintf('Remote PayPal plan mismatch [entitlement %d]: %s', $entitlement->eid, $remote['plan_id'] ?? '(empty)'));
        }
      }
      catch (\Throwable $e) {
        $remote_plan_mismatches++;
        $this->output()->writeln(sprintf('Unable to audit entitlement %d plan: %s', $entitlement->eid, $e->getMessage()));
      }
    }
    $this->output()->writeln('Remote PayPal plan mismatches/errors: ' . $remote_plan_mismatches);

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

    $invalid_campaigns = 0;
    foreach ($this->entityTypeManager->getStorage(LmsSubscriptionCampaign::ENTITY_TYPE_ID)->loadMultiple() as $campaign) {
      if (!$campaign instanceof LmsSubscriptionCampaign || !$campaign->status()) {
        continue;
      }
      $errors = $this->planCatalog->validateCampaign($campaign);
      if (!$errors) {
        $this->output()->writeln(sprintf('Subscription campaign valid: %s', $campaign->id()));
        continue;
      }
      $invalid_campaigns++;
      foreach ($errors as $error) {
        $this->output()->writeln(sprintf('Subscription campaign invalid [%s]: %s', $campaign->id(), $error));
      }
    }
    $this->output()->writeln('Invalid subscription campaigns: ' . $invalid_campaigns);
  }
}
