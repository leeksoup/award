<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

/** Decides access only from an identified subscription and approved plans. */
final class SubscriptionAccessPolicy {

  /**
   * Validates remote identity and resolves the paid tier without side effects.
   *
   * @param array $plan_tiers
   *   Configured plan IDs mapped to base or vip for the original environment.
   * @param array $standard_plans
   *   Standard base/vip plan IDs, keyed by tier, for permitted revisions.
   *
   * @return array
   *   The approved plan, VIP state, and valid revision/its payment state.
   */
  public static function evaluate(array $entitlement, array $remote, array $plan_tiers, array $standard_plans, ?array $change, int $now): array {
    $subscription_id = (string) ($entitlement['paypal_subscription_id'] ?? '');
    if (($entitlement['purchase_type'] ?? '') !== 'recurring' || $subscription_id === '' || ($remote['id'] ?? '') !== $subscription_id) {
      throw new \DomainException('PayPal subscription identity does not match the entitlement.');
    }
    $approved = (string) ($entitlement['paypal_plan_id'] ?? '');
    $remote_plan = (string) ($remote['plan_id'] ?? '');
    if ($approved === '' || !isset($plan_tiers[$approved]) || $remote_plan === '') {
      throw new \DomainException('The approved or remote PayPal plan is missing or no longer configured.');
    }
    if (!in_array($remote['status'] ?? '', ['ACTIVE', 'SUSPENDED', 'CANCELLED', 'EXPIRED', 'APPROVAL_PENDING', 'APPROVED'], TRUE)) {
      throw new \DomainException('PayPal returned an unknown subscription status.');
    }

    // Earlier releases overwrote the stored plan on approval, before billing.
    // Recover the paid source tier only from a valid approved revision whose
    // source tier still agrees with the entitlement's existing benefit.
    $source_plan = (string) ($change['from_plan_id'] ?? '');
    $legacy_approval = ($change['status'] ?? '') === 'approved'
      && ($change['target_plan_id'] ?? '') === $approved
      && ($change['from_tier'] ?? '') === (empty($entitlement['vip_active']) ? 'base' : 'vip');
    // A stale, abandoned, or malformed revision must never enlarge the set of
    // allowed plans. Revisions always target standard plans after a campaign.
    if ($change && (
      (int) ($change['eid'] ?? 0) !== (int) $entitlement['eid']
      || !in_array($change['status'] ?? '', ['approval_pending', 'approved'], TRUE)
      || ($source_plan !== $approved && !$legacy_approval)
      || !isset($plan_tiers[$source_plan])
      || ($change['from_tier'] ?? '') !== $plan_tiers[$source_plan]
      || !in_array($change['to_tier'] ?? '', ['base', 'vip'], TRUE)
      || $change['to_tier'] === $change['from_tier']
      || empty($standard_plans[$change['to_tier']])
      || ($change['target_plan_id'] ?? '') !== $standard_plans[$change['to_tier']]
      || (int) ($change['requested'] ?? 0) <= 0
      || (int) $change['requested'] > $now
      || (int) ($change['effective'] ?? 0) < (int) $change['requested']
    )) {
      $change = NULL;
    }
    if ($change && $legacy_approval) {
      $approved = $source_plan;
    }
    if ($plan_tiers[$approved] === 'vip' && !empty($entitlement['activated']) && empty($entitlement['vip_active'])) {
      throw new \DomainException('The stored VIP plan does not have a verified active VIP benefit.');
    }
    $on_target = $change && $remote_plan === $change['target_plan_id'];
    if ($remote_plan !== $approved && !$on_target) {
      throw new \DomainException('PayPal returned a plan that is not authorized for this entitlement.');
    }
    $last_payment = !empty($remote['billing_info']['last_payment']['time'])
      ? strtotime((string) $remote['billing_info']['last_payment']['time'])
      : FALSE;
    $paid = $on_target && ($remote['status'] ?? '') === 'ACTIVE'
      && $last_payment && $last_payment <= $now
      && $last_payment >= (int) $change['effective'];
    $tier = $paid ? $change['to_tier'] : $plan_tiers[$approved];

    return [
      'plan_id' => $paid ? $change['target_plan_id'] : $approved,
      'vip_active' => $tier === 'vip',
      'change' => $change,
      'on_target' => (bool) $on_target,
      'paid_change' => (bool) $paid,
    ];
  }

}
