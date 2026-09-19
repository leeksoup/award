<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Drush\Commands;

use Drupal\commerce_lms_entitlements\Entity\LmsSubscriptionCampaign;
use Drupal\commerce_lms_entitlements\PayPalCampaignPlanManager;
use Drupal\commerce_lms_entitlements\PayPalPlanCatalog;
use Drupal\commerce_lms_entitlements\PayPalSubscriptionOperations;
use Drupal\commerce_lms_entitlements\PayPalVipPlanManager;
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
    private PayPalCampaignPlanManager $campaignPlanManager,
    private PayPalVipPlanManager $vipPlanManager,
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
    if ($this->database->schema()->fieldExists('commerce_lms_entitlement', 'checkout_token')) {
      $pending_query = $this->database->select('commerce_lms_entitlement', 'e')
        ->condition('purchase_type', 'recurring')
        ->condition('status', 'pending')
        ->isNull('paypal_subscription_id');
      $missing_seal = $pending_query->orConditionGroup()
        ->isNull('checkout_token')
        ->isNull('checkout_snapshot');
      $unsealed = $pending_query
        ->condition($missing_seal)
        ->countQuery()->execute()->fetchField();
      $this->output()->writeln('Legacy pending subscriptions without automatic return recovery: ' . $unsealed);
    }
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

  /** Previews or creates one subscription campaign's PayPal plan matrix. */
  #[CLI\Command(name: 'commerce-lms-entitlements:create-campaign-plans', aliases: ['clecp'])]
  #[CLI\Argument(name: 'campaign_id', description: 'Subscription campaign configuration ID.')]
  #[CLI\Option(name: 'environment', description: 'PayPal environment: sandbox (default) or live.')]
  #[CLI\Option(name: 'apply', description: 'Create missing plans and save their IDs to campaign configuration.')]
  #[CLI\Option(name: 'confirm-live', description: 'Required confirmation token for live creation.')]
  #[CLI\Usage(name: 'drush commerce-lms-entitlements:create-campaign-plans launch_free_vip', description: 'Preview the sandbox plan matrix without changing PayPal or Drupal.')]
  #[CLI\Usage(name: 'drush commerce-lms-entitlements:create-campaign-plans launch_free_vip --apply', description: 'Create missing sandbox plans and save their IDs.')]
  #[CLI\Usage(name: 'drush commerce-lms-entitlements:create-campaign-plans launch_free_vip --environment=live --apply --confirm-live=CREATE-LIVE-PAYPAL-PLANS', description: 'Create and save missing live plans after explicit confirmation.')]
  public function createCampaignPlans(
    string $campaign_id,
    array $options = [
      'environment' => 'sandbox',
      'apply' => FALSE,
      'confirm-live' => NULL,
    ],
  ): void {
    $environment = strtolower(trim((string) ($options['environment'] ?? 'sandbox')));
    if (!in_array($environment, ['sandbox', 'live'], TRUE)) {
      throw new \InvalidArgumentException('The --environment option must be sandbox or live.');
    }
    $apply = !empty($options['apply']);
    if ($apply && $environment === 'live' && ($options['confirm-live'] ?? '') !== 'CREATE-LIVE-PAYPAL-PLANS') {
      throw new \InvalidArgumentException('Live plan creation requires --confirm-live=CREATE-LIVE-PAYPAL-PLANS.');
    }

    $campaign = $this->entityTypeManager->getStorage(LmsSubscriptionCampaign::ENTITY_TYPE_ID)->load($campaign_id);
    if (!$campaign instanceof LmsSubscriptionCampaign) {
      throw new \InvalidArgumentException(sprintf('Subscription campaign %s does not exist.', $campaign_id));
    }
    $entries = $this->campaignPlanManager->provision($campaign, $environment, $apply);
    $rows = [];
    foreach ($entries as $entry) {
      $rows[] = [
        $entry['offer_id'],
        strtoupper($entry['tier']),
        $entry['intro'],
        $entry['regular'],
        $entry['intro_cycles'],
        $entry['product_id'],
        $entry['status'],
        $entry['plan_id'] ?: '(pending)',
      ];
    }
    $this->io()->table(
      ['Offer', 'Tier', 'Intro price', 'Renewal price', 'Intro cycles', 'Product', 'Result', 'Plan ID'],
      $rows,
    );
    if ($apply) {
      $this->logger()->success(sprintf('Saved the complete %s PayPal plan matrix for campaign %s.', $environment, $campaign_id));
    }
    else {
      $this->logger()->notice(sprintf('Dry run only. Re-run with --apply to create missing %s plans.', $environment));
    }
  }

  /** Previews or creates standard VIP-inclusive PayPal plans. */
  #[CLI\Command(name: 'commerce-lms-entitlements:create-vip-plans', aliases: ['clevp'])]
  #[CLI\Option(name: 'environment', description: 'PayPal environment: sandbox (default) or live.')]
  #[CLI\Option(name: 'apply', description: 'Create missing plans and save their IDs to LMS offer configuration.')]
  #[CLI\Option(name: 'confirm-live', description: 'Required confirmation token for live creation.')]
  #[CLI\Usage(name: 'drush commerce-lms-entitlements:create-vip-plans', description: 'Preview missing sandbox standard VIP plans without changing PayPal or Drupal.')]
  #[CLI\Usage(name: 'drush commerce-lms-entitlements:create-vip-plans --environment=live --apply --confirm-live=CREATE-LIVE-PAYPAL-PLANS', description: 'Create, validate, and save missing live standard VIP plans.')]
  public function createVipPlans(
    array $options = [
      'environment' => 'sandbox',
      'apply' => FALSE,
      'confirm-live' => NULL,
    ],
  ): void {
    $environment = strtolower(trim((string) ($options['environment'] ?? 'sandbox')));
    if (!in_array($environment, ['sandbox', 'live'], TRUE)) {
      throw new \InvalidArgumentException('The --environment option must be sandbox or live.');
    }
    $apply = !empty($options['apply']);
    if ($apply && $environment === 'live' && ($options['confirm-live'] ?? '') !== 'CREATE-LIVE-PAYPAL-PLANS') {
      throw new \InvalidArgumentException('Live plan creation requires --confirm-live=CREATE-LIVE-PAYPAL-PLANS.');
    }

    $entries = $this->vipPlanManager->provision($environment, $apply);
    $rows = [];
    foreach ($entries as $entry) {
      $rows[] = [
        $entry['offer_id'],
        $entry['price'],
        $entry['product_id'],
        $entry['status'],
        $entry['plan_id'] ?: '(pending)',
      ];
    }
    $this->io()->table(['Offer', 'VIP price', 'Product', 'Result', 'Plan ID'], $rows);
    if ($apply) {
      $this->logger()->success(sprintf('Saved the %s standard VIP PayPal plan mappings.', $environment));
    }
    else {
      $this->logger()->notice(sprintf('Dry run only. Re-run with --apply to create missing %s standard VIP plans.', $environment));
    }
  }
}
