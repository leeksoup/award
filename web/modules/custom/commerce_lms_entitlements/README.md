# Commerce LMS Entitlements

This module is the LMS access layer for the contributed `commerce_paypal` and
`commerce_paypal_subscriptions` modules. It intentionally does not implement a
payment gateway or PayPal checkout flow.

It is designed for Group 3.2 and LMS 1.2.1. The Group 3 membership API remains
the authority for normal LMS `view` and `take` access checks.

This project applies the Composer patch
`patches/commerce_paypal_subscriptions-1.0.0-commerce-paypal-1.12-sdk-factory.patch`
to correct stale SDK-factory service arguments in
`commerce_paypal_subscriptions` 1.0.0. It changes dependency injection only;
it does not replace the contributed checkout or subscription SDK. The local
copy matches the upstream issue patch and is retained so installs are
reproducible without fetching a remote patch URL.

It also applies
`patches/commerce_paypal_subscriptions-1.0.0-commerce-3-checkout-form.patch`.
Commerce 3 supplies a generic checkout payment-method form when a gateway does
not declare one. That generic form asks the inherited regular PayPal gateway to
create a payment method before subscription approval, when no PayPal order ID
exists. The patch explicitly selects Commerce PayPal's Smart Buttons checkout
form, which defers payment-method creation until the subscription approval
step. Subscription gateways must use the **Smart payment buttons** payment
solution; custom card fields are not a subscription checkout path.

For a full code and operational guide, read
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). It documents every source file,
table, lifecycle state, route, queue, and PayPal/Group integration boundary.
The VIP checkout, PayPal tier-change, and session-booking workflow is documented
separately in [`docs/VIP.md`](docs/VIP.md).

## Offer model

Create Commerce variations for monthly or quarterly, annual, and lifetime
access. Create an LMS offer for each variation at
`/admin/commerce/config/lms-offers`.

- Monthly, quarterly, and annual offers use the contributed PayPal subscription
  gateway and pre-created PayPal plans.
- Lifetime uses normal Commerce PayPal Checkout and has no plan ID.
- Each recurring offer stores separate sandbox and live gateway/product/plan
  mappings plus an explicit active checkout environment. Sandbox IDs remain
  manually entered. The offer form loads available live products and plans
  from PayPal using the selected live gateway credentials.
- Each offer contains an ordered `COURSE_ID:CLASS_ID` target list.
  Administrators select the Classes; buyers only select the learner.
- At checkout, the module filters Commerce's available gateways to that sole
  configured gateway. It never falls back from a recurring offer to one-time
  PayPal Checkout.
- A recurring offer can additionally map base-plus-VIP plans for sandbox and
  live. The VIP checkbox adds a labeled recurring adjustment while retaining
  one order item, one PayPal approval, and one PayPal subscription.

The module validates that every selected Class is an existing `lms_class` child
of its configured `lms_course`. It does not require a Course to have only one
Class.

## Access lifecycle

The learner checkout pane stores an existing user ID or an unregistered email
address on the order. It does not send mail. The contributed subscription
module dispatches a plan-selection event; this module validates the offer,
creates a pending entitlement, and supplies the configured plan ID. At that
boundary it seals the exact quantity-one cart, adjustments, coupons, gateway,
and total behind a random PayPal `custom_id`. Approval is finalized on the
server before the browser redirect. If that request is lost, a verified
webhook can correlate the remote subscription to the sealed entitlement and
finish the same idempotent operation. It never derives the payment from a cart
that changed after PayPal approval began. A 30-day invitation is created and
sent only when PayPal reports the subscription active or a lifetime order
becomes fully paid.

Invitation and VIP messages use Drupal's traditional `hook_mail()` API. The
module requires Mailer Override and enables its module-level override so these
messages use the site's configured Symfony Mailer transport instead of falling
back to PHP `mail()`. Export `mailer_override.settings` after update `10016` so
a later configuration import cannot disable this routing.

An invitation can create a validated account only when its normalized email is
not already registered. Existing accounts must authenticate through Drupal's
normal login form and return to the invitation URL before claiming access.

PayPal subscription webhooks arrive at
`/commerce-lms-entitlements/paypal/webhook/GATEWAY_ID`. The module verifies the
signature through the contributed PayPal SDK, deduplicates event IDs, queues
work, then fetches the authoritative subscription detail before changing LMS
access. Lifetime access is granted only once its normal Commerce payment is
completed. Cron also queues reconciliation: it expires paid-through
cancellations and refreshes each nonterminal recurring subscription from
PayPal, so missed webhook deliveries can self-heal.

Each entitlement records whether it created a Class membership. Revocation
never deletes a manual membership or one still supported by another active
entitlement.

## Purchaser cancellation and guarantee

Purchasers can review their entitlement records at `/my-lms-subscriptions`.
For recurring offers they can cancel renewal; the module asks PayPal to cancel
then fetches the resulting subscription detail, retaining access through the
PayPal-provided paid-through date. During the first 40 calendar days after
activation, the cancellation form also offers the guarantee path. It cancels a
recurring subscription (when applicable), revokes LMS access immediately, and
refunds the recorded initial PayPal capture. A failed refund remains in
`guarantee_refund_pending` for staff review instead of restoring access.

The PayPal gateway configuration must contain its normal client ID, client
secret, mode, and webhook ID. The custom cancellation/refund calls use those
same credentials; no duplicate credentials are stored in this module.

## Live plan discovery and validation

Edit a recurring offer, select its live PayPal Subscriptions gateway, and use
**Load live plans from PayPal**. The module calls the live catalog and billing
plan APIs server-side; API credentials are never exposed to the browser. A
selected live plan can be saved only when it is active, has no trial cycles,
does not support variable quantity, has exactly one regular billing cycle, and
matches both the offer's expected interval and the Commerce variation's price
and currency. The validated PayPal product ID is stored with the plan.

The **Active checkout environment** controls which mapping is permitted at
checkout. Keep it on sandbox during testing and change it to live only when the
live mapping is complete. The module never automatically prefers live merely
because both gateways are enabled.

Run `drush commerce-lms-entitlements:audit` to re-fetch and validate every
configured live plan. This is an explicit/scheduled audit rather than a plan
webhook mirror; the subscription webhook pipeline continues to accept customer
subscription lifecycle events only. Drupal cron also queues the same live-plan
audit and logs discrepancies without delaying the cron request.

## Introductory subscription campaigns

Reusable coupon-backed campaigns are managed at
`/admin/commerce/config/lms-subscription-campaigns`. A campaign maps each
eligible recurring LMS offer to pre-created sandbox/live PayPal plans. Each
campaign plan must have exactly one finite `TRIAL` cycle at the configured
introductory price followed by one infinite `REGULAR` cycle at the offer's
normal base or base-plus-VIP price.

Create a new campaign as a disabled draft before generating its PayPal plans.
Disabled drafts validate their offers and prices but do not require plan IDs;
enabling a campaign requires valid plans for the active checkout environment.

Create the campaign before its Commerce promotion. On the promotion, choose
the **LMS subscription campaign** offer plugin and select the campaign. Create
its coupon and configure Commerce's availability and usage limits normally.
Recurring checkout accepts zero coupons or exactly one coupon using this offer
plugin; it rejects stacking and unrelated coupons rather than allowing Drupal's
order total to diverge from PayPal billing.

Add the **Subscription offer** pane to the checkout flow's order-information
step so buyers see the introductory payment count, normal renewal price, VIP
treatment, and campaign terms. A launch campaign forces the existing VIP pane
on while its coupon remains attached, then restores the buyer's previous VIP
choice if the coupon is removed. Its introductory amount may discount the
base subscription while including VIP at no additional cost, but it cannot
exceed the offer's normal base-only price; renewal uses the full
base-plus-VIP price.

Run `drush commerce-lms-entitlements:audit` after creating or changing a
campaign. Enabled campaigns are checked against PayPal for status, product,
cadence, introductory cycles and price, and indefinite regular renewal price.
Commerce coupon limits remain authoritative; the module does not reserve a
redemption before checkout completes.

### Campaign PayPal plan generation

The site must already have its shared standard base and, when enabled,
VIP-inclusive PayPal plans configured on each LMS offer. The generator never
recreates those shared plans. It derives each campaign plan's product, cadence,
currency, introductory cycles, introductory price, and renewal price from the
standard plan, Commerce variation, offer, and campaign configuration.

Preview the missing sandbox matrix without changing PayPal or Drupal:

```bash
drush commerce-lms-entitlements:create-campaign-plans CAMPAIGN_ID
```

Create the missing sandbox plans, validate every returned plan, and save the
complete set of IDs to the campaign only after all requested plans succeed:

```bash
drush commerce-lms-entitlements:create-campaign-plans CAMPAIGN_ID --apply
```

Live creation uses the offer's live gateway and standard plan. It requires a
different environment option and an exact confirmation token:

```bash
drush commerce-lms-entitlements:create-campaign-plans CAMPAIGN_ID \
  --environment=live \
  --apply \
  --confirm-live=CREATE-LIVE-PAYPAL-PLANS
```

The command uses deterministic `PayPal-Request-Id` values. It also records
each successfully created plan temporarily in Drupal state, so rerunning after
a partial failure validates and reuses that plan instead of creating another.
The state record is removed after the complete matrix is saved. Existing
configured plan IDs are validated and never overwritten. Changing Commerce
coupon codes, dates, limits, promotion descriptions, or campaign terms does
not affect the generated plan specification.

After a successful apply, export the updated campaign configuration and run
the remote audit:

```bash
drush cex -y
drush commerce-lms-entitlements:audit
```

### Standard VIP PayPal plan generation

VIP-enabled recurring offers also require a standard VIP-inclusive plan that
has one infinite `REGULAR` cycle and no introductory `TRIAL` cycle. Preview
missing sandbox mappings with:

```bash
drush commerce-lms-entitlements:create-vip-plans
```

Create, validate, and save missing live mappings only with explicit
confirmation:

```bash
drush commerce-lms-entitlements:create-vip-plans \
  --environment=live \
  --apply \
  --confirm-live=CREATE-LIVE-PAYPAL-PLANS
```

The command derives product, cadence, currency, and price from the standard
base plan, Commerce variation, and configured VIP surcharge. It validates and
preserves existing mappings, uses deterministic PayPal request IDs, and
records recovery state after a partially successful run. After applying, run
`drush cex -y` and `drush commerce-lms-entitlements:audit`.

## VIP live sessions

VIP uses Recurring Events 3.x for event series, instances, registrants, and
capacity. Configure the protected LMS hub, eligible instance-registration
series, shared meeting URL, and booking cutoff at
`/admin/commerce/config/lms-vip`. Learners with an active VIP entitlement book
at `/vip-sessions`; the module enforces one booking per site-timezone calendar
month and disables selection when contrib reports no remaining capacity.

Existing subscribers can add or remove VIP from `/my-lms-subscriptions`.
Drupal asks PayPal to revise the existing subscription and sends the purchaser
through PayPal re-consent. No second subscription is created. The tier remains
unchanged until a successful payment at the next billing boundary, so the
workflow deliberately performs no proration.

## Staging checks

Test monthly/quarterly/annual base and VIP plan selection, sandbox/live environment
selection, campaign coupons and disclosure, live plan discovery and mismatch validation, lifetime PayPal
payment, invitation claim, multi-Class Course target validation, valid/invalid
and duplicate webhooks, cancellation timing, failed payment/recovery, and
Group `view`/`take` access. Also test PayPal revision approval/abandonment,
renewal-boundary tier activation, monthly booking quota, cutoff, capacity,
booking changes, and mail delivery.
This repository has no bootstrapped Commerce site, so those checks are not yet
run here.
