# Introductory Coupons for PayPal LMS Subscriptions

## Summary

Implement reusable subscription campaigns in `commerce_lms_entitlements`.
A Drupal Commerce coupon selects a curated campaign whose eligible LMS offers
map to pre-created PayPal plans containing one finite introductory billing
cycle followed by one infinite regular billing cycle.

The first two campaigns are:

- A launch campaign, globally limited to 12 coupon redemptions, that
  automatically includes VIP. It charges the base-only price for the
  configured introductory payments and then renews at the full base-plus-VIP
  price.
- A later introductory-discount campaign that discounts the selected base or
  base-plus-VIP tier for the configured introductory payments and then renews
  at the normal price.

Existing subscriptions, lifetime purchases, and recurring checkouts without a
coupon retain their current behavior.

## Subscription campaign configuration

Add an `LmsSubscriptionCampaign` config entity with Commerce administration
pages. Each campaign contains:

- A machine name, label, enabled status, and customer-facing terms.
- A behavior of either `free_vip_launch` or `intro_discount`.
- One or more mappings keyed by `lms_offer` ID.
- For each offer mapping, an introductory cycle count, introductory base and
  VIP prices as applicable, and sandbox/live PayPal plan IDs for both tiers as
  applicable.

The launch behavior requires a VIP campaign plan, forces VIP selection, and
requires its introductory price to equal the normal base-only price. The
normal discount behavior permits the configured base and VIP tiers.

## Commerce promotion and checkout integration

Add an order-level Commerce Promotion Offer plugin named **LMS subscription
campaign**. Its configuration selects one campaign. Commerce promotions and
coupons continue to control coupon codes, dates, and usage limits; the launch
coupon's total usage limit is configured as 12 in Commerce.

Resolve campaigns through:

`order coupon -> promotion -> campaign offer plugin -> campaign config`

Do not compare raw coupon-code strings. A recurring order may contain no
coupon, which selects its standard PayPal plan, or exactly one coupon backed
by the campaign offer plugin. Reject multiple, unrelated, disabled, or
incompatible coupons before PayPal subscription creation.

Run VIP processing before Commerce promotion processing. For launch coupons,
save the previous VIP choice, force VIP on, add the normal VIP surcharge, and
then apply a promotion adjustment that makes the order total equal the
campaign's introductory price. Restore the previous VIP choice if the coupon
is removed. Show the introductory amount and duration, normal renewal amount,
VIP treatment, and administrator-authored terms during checkout.

## PayPal plan selection and validation

Add a campaign resolver that produces one normalized subscription-plan
selection containing the campaign, offer, tier, environment, PayPal product
and plan IDs, introductory amount and cycles, regular amount, and promotion
and coupon UUIDs.

Refactor `PayPalPlanSubscriber` and `EntitlementManager` to use that same
selection. The entitlement manager must not replace an already selected
campaign plan with an offer's standard plan.

Extend `PayPalPlanCatalog` to verify that every campaign plan:

- Exists and is active.
- Uses the expected product and currency.
- Matches the offer's billing frequency and does not use quantity billing.
- Has exactly one finite `TRIAL` billing cycle with the configured count and
  introductory price.
- Has exactly one following infinite `REGULAR` billing cycle at the normal
  base or base-plus-VIP price.

Provide form validation before enabling a campaign and a read-only Drush audit
for all enabled campaigns.

## Entitlement lifecycle

Add update hook `commerce_lms_entitlements_update_10013()` after verifying the
current highest hook. Add nullable entitlement audit columns for the campaign
ID, promotion UUID, and coupon UUID. Continue using the existing
`paypal_plan_id` column as the authoritative selected plan. Do not backfill
existing rows.

Webhook and reconciliation behavior must accept the recorded campaign plan,
reject remote plan mismatches, and preserve the current activation,
membership, cancellation, and expiration behavior.

For campaign subscriptions, block base/VIP plan changes while PayPal reports a
`TRIAL` cycle with `cycles_remaining > 0`. Permit normal changes after the
trial completes. Fail closed and log the problem when the trial state cannot
be determined.

Commerce's coupon usage limit remains authoritative. No custom redemption
reservation table will be introduced; simultaneous final checkouts can
theoretically race around the twelve-use limit.

## Tests and acceptance

Automated tests cover:

- Campaign configuration and validation across multiple offers.
- Sandbox/live and base/VIP plan selection.
- Automatic launch VIP selection and restoration after coupon removal.
- Exact introductory order totals.
- Rejection of unsupported, multiple, disabled, and incompatible coupons.
- Remote plan status, product, currency, cadence, sequence, cycle count, and
  price validation.
- Persistence and webhook validation of campaign plan metadata.
- Blocking tier changes during introductory cycles and allowing them after
  completion.
- Regression coverage for ordinary recurring purchases, lifetime purchases,
  invitations, memberships, and non-campaign VIP purchases.

Manual sandbox acceptance verifies the matching Drupal and PayPal schedules,
webhook processing, LMS access, coupon removal, usage limits, and tier-change
behavior. Before deployment, run PHPUnit, PHPCS when available, PHP and YAML
syntax checks, `git diff --check`, `composer validate --no-check-publish`,
`drush updb`, cache rebuild, and the campaign audit.

## Deployment

1. Deploy code and run update `10013` before creating campaign configuration.
2. Create matching sandbox PayPal plans and campaign mappings.
3. Create Commerce promotions using the campaign offer plugin and configure
   their coupons, dates, and limits.
4. Complete sandbox acceptance.
5. Configure and validate live PayPal plan IDs.
6. Export site configuration and enable production coupons only after the
   live audit passes.

Document campaign setup, PayPal plan construction, validation, rollout,
rollback, and the accepted coupon-limit race in the module's operational
documentation.
