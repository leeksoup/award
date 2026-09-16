# VIP checkout, subscription tiers, and live sessions

## Purpose and boundaries

VIP is an order bump on the existing recurring LMS purchase. It is not a
second Commerce item or a second PayPal subscription. A monthly or annual offer
has two PayPal plans with the same product and cadence:

```text
base Commerce variation + no adjustment -> base PayPal plan
base Commerce variation + VIP adjustment -> VIP-inclusive PayPal plan
```

This preserves the module's one-item/quantity-one invariant and gives the buyer
one PayPal approval during checkout. Lifetime VIP, proration, waitlists,
reminder messages, and unique meeting links are intentionally outside v1.

PayPal owns billing and Recurring Events owns event instances, registrants, and
capacity. This module owns tier eligibility, LMS hub membership, the monthly
booking quota, and the relationship between those systems.

## Dependency and deployment

Recurring Events 3.x requires Drupal 10.3 or newer (or Drupal 11). Install the
Composer requirement before enabling/updating this module:

```bash
composer require 'drupal/recurring_events:^3.0'
drush en recurring_events recurring_events_registration -y
drush updb -y
drush cr
```

Update `10012` adds tier fields to existing entitlements, marks existing rows
as base tier, distinguishes base/VIP membership ownership, and creates the plan
change and booking tables. It does not enroll existing subscribers in VIP.

## PayPal plan setup

For every VIP-enabled recurring offer, create base and VIP-inclusive plans in
each active PayPal environment. Each pair must:

- belong to the same PayPal product;
- be active and use the same monthly, quarterly, or annual frequency;
- use one regular billing cycle, with no trial or variable quantity;
- use the Commerce variation currency; and
- price the VIP plan at variation price plus the offer's VIP surcharge.

Edit the LMS offer, enable VIP, enter the environment-specific VIP plan IDs and
surcharge, then save. Live mappings are fetched and validated through the
existing plan catalog. Sandbox mappings remain explicitly configured for test
isolation. A sandbox-active offer may retain its existing live base mapping
while its live VIP plan is still blank; switching the offer to live remains
blocked until the VIP plan is configured, and the audit continues to report
the incomplete live pair. Run the audit command after any PayPal price or plan
change.

## Initial checkout flow

`VipUpgradePane` appears before payment only for a recurring VIP-enabled offer.
It stores `commerce_lms_vip_selected` on the order. `VipOrderProcessor` removes
any prior module adjustment and adds one locked `VIP upgrade` fee, making order
refreshes idempotent. `PayPalPlanSubscriber` then supplies either the base or
VIP-inclusive plan ID to the contributed subscription checkout.

`ensureEntitlement()` snapshots `paypal_plan_id` and `vip_selected`. VIP access
is not granted until PayPal reports the subscription `ACTIVE`. At activation,
base Course/Class memberships are granted normally and the configured VIP hub
Class is recorded as the separate `vip` benefit.

## Later upgrade or downgrade

The purchaser uses **Add VIP** or **Remove VIP** on
`/my-lms-subscriptions`. `PlanChangeManager` checks ownership, active recurring
status, absence of another unresolved change, and the target plan. It fetches
both PayPal plans again to require an active target under the same product and
cadence.

The module stores a random state-token hash and the existing
`next_billing_time`, calls PayPal's subscription `revise` endpoint, and sends
the purchaser to the returned approval URL. The plaintext state token exists
only in the signed workflow URL. On return, Drupal fetches the subscription; a
return callback alone is never treated as billing truth.

An abandoned approval marks the change `abandoned` and leaves the original
subscription and access untouched. If PayPal's detail endpoint has not caught
up with the browser return, the record remains pending; a verified webhook or
reconciliation fetch can confirm it later.

PayPal applies the new amount at the next billing cycle and does not prorate.
Accordingly, `vip_active` changes only when `last_payment.time` reaches the
stored effective boundary:

- upgrade: base access continues; VIP hub access begins after the successful
  VIP-priced renewal;
- downgrade: VIP access remains through the paid period and is removed after
  the successful base-priced renewal;
- failed renewal: normal suspension removes module-owned access; recovery
  restores the tier supported by the authoritative plan/payment state.

The original completed Commerce order is never rewritten to resemble a later
sale. `commerce_lms_plan_change` is the audit history for the revision.

## VIP LMS hub and session configuration

Create one LMS Course and select one of its LMS Class children as the shared
VIP hub. Add the session description/content to that hub. Configure its IDs at
`/admin/commerce/config/lms-vip` along with the protected shared meeting URL,
cutoff hours, and one or more Recurring Events series IDs.

Configure each series in Recurring Events as:

- individual event-instance registration;
- the desired per-instance capacity;
- waitlist disabled; and
- published future instances for every time buyers may select.

The number and schedule of instances can change without changing PayPal plans
or offers. The meeting URL is rendered only after the current account passes
the active VIP entitlement check.

## Booking behavior

At `/vip-sessions`, `VipBookingManager` loads only configured future instances
before the cutoff. It calls Recurring Events'
`registration_creation_service`, using `setEventInstance()`,
`retrieveAvailability()`, and `hasWaitlist()`. The module creates normal
fieldable `registrant` entities with the contrib `user_id`, email, event
instance, and non-waitlisted state.

The `commerce_lms_vip_booking` table is a quota/ownership ledger, not a
replacement event system. Its unique `(uid, month_key)` key guarantees one
registration per learner per calendar month. The month comes from the event
start converted to Drupal's configured site timezone.

A lock serializes simultaneous changes for the learner/month. For a change,
the target capacity is checked and the new registrant is saved before the old
one is removed; a failed target booking leaves the old reservation intact.
Cancellation deletes both the owned registrant and ledger row, freeing the
month for another choice. Booking, change, and cancellation send mail only to
the non-empty learner-account email.

Non-staff native registrant forms for configured VIP series are blocked so
they cannot bypass the monthly quota. Staff with `administer vip sessions`
retain Recurring Events' normal administrative tools.

## Membership and revocation safety

Membership rows now include `benefit = base|vip`. A downgrade revokes only the
VIP benefit. Full cancellation/suspension revokes all benefits. The original
ownership rules still apply: a pre-existing manual Class membership is never
deleted, and another active entitlement supporting the same learner/Class
prevents removal.

When the learner loses their last active VIP entitlement, future VIP booking
ledger rows and their module-owned registrants are removed so cancelled access
does not consume event capacity.

## Operations and recovery

```bash
drush commerce-lms-entitlements:audit
drush queue:run commerce_lms_entitlements_webhook
drush queue:run commerce_lms_entitlements_reconcile
```

The audit reports invalid live plan pairs, webhook/refund failures, tier
changes stalled for more than 48 hours, and learners with bookings but no
active VIP entitlement. Resolve the PayPal/configuration cause and run
reconciliation; do not directly flip `vip_active` or delete Group membership
rows.

Before live launch, test initial base and VIP checkout, approval abandonment,
upgrade and downgrade re-consent, a successful renewal, failed-payment
suspension/recovery, purchaser-versus-learner identities, concurrent last-seat
booking, month boundaries in the site timezone, cutoff enforcement, email
delivery, and safe removal of only module-owned VIP membership.
