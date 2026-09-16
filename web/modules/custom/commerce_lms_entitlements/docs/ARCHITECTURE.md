# Commerce LMS Entitlements: architecture and code guide

This module turns a Commerce purchase into controlled membership of one or more
LMS Classes. It is deliberately an **access layer**, not a replacement payment
gateway or subscription engine.

The two contributed modules own PayPal checkout:

- `commerce_paypal` owns a one-time PayPal Checkout payment. This is used for
  the lifetime variation.
- `commerce_paypal_subscriptions` owns PayPal subscription approval and stores
  the approved PayPal subscription ID on the Commerce order. This is used for
  monthly, quarterly, and annual variations.

PayPal remains the billing authority. Group 3.2 and LMS 1.2.1 remain the access
authority: a learner has LMS access because they are a member of the selected
`lms_class` Group. This module only creates or removes that membership when it
can prove the entitlement owns it.

## Design invariants

1. One order contains exactly one variation at quantity one. An offer lookup
   rejects all other carts; it does not try to split a cart into entitlements.
2. One variation resolves to exactly one enabled offer. No offer, or two offers
   for the same variation, is a configuration error.
3. An offer is a fixed, administrator-controlled Course-to-Class bundle. A
   purchaser selects the learner, never a Class.
4. Every configured target must be an `lms_class` child relationship of the
   named `lms_course`. A Course may have multiple Classes; the offer records
   which one is included.
5. The webhook payload is evidence of a change, not the authoritative billing
   state. After signature verification the worker fetches the current PayPal
   subscription detail before changing access.
6. A membership is removed only when this module created it and no other active
   entitlement still supports it. Manual/pre-existing Group memberships survive
   revocation.
7. A guarantee refund failure does not restore access. It remains visible as
   recovery work for staff.
8. A recurring offer has separate sandbox and live mappings, but exactly one
   explicit environment is active for checkout. The presence of a live
   gateway never causes an automatic switch from sandbox to live billing.
9. VIP remains an order-level adjustment on the sole recurring item. Base and
   VIP are paired PayPal plans, never simultaneous subscriptions.
10. A tier revision changes access only after PayPal confirms the target plan
    and a successful payment reaches the stored billing boundary.
11. Recurring Events owns session capacity and registrants. The module's
    booking ledger adds only active-VIP authorization and a one-per-month quota.

## Setup sequence

1. Install and configure `commerce_paypal` and
   `commerce_paypal_subscriptions`. The recurring gateway must be the
   contributed `paypal_checkout_subscriptions` plugin and include the PayPal
   webhook ID, client ID, client secret, and `test` or `live` mode. Select
   **Smart payment buttons**, not custom card fields. The repository's
   Commerce 3 checkout-form patch ensures the payment-information pane defers
   payment-method creation until PayPal subscription approval; no separate
   subscription checkout-flow plugin is expected in Commerce's checkout-flow
   administration screen.
2. Create the PayPal monthly or quarterly and annual plans in both PayPal
   environments as needed. Sandbox and live catalogs have different IDs. No
   plan is required for the lifetime variation.
3. Create Commerce product variations for the selected recurring periods and
   lifetime one-time access.
4. Configure the Commerce checkout flow to enable the **Learner** and optional
   **VIP upgrade** panes from
   `src/Plugin/Commerce/CheckoutPane/LearnerPane.php`.
5. Create one offer per variation at
   `/admin/commerce/config/lms-offers`. Recurring offers store independent
   sandbox and live gateway/product/plan mappings. Enter sandbox IDs manually;
   select a live gateway and use **Load live plans from PayPal** to discover
   the live mapping. Choose the explicit active checkout environment and enter
   each bundle item as `COURSE_ID:CLASS_ID` in its intended order.
6. In PayPal, subscribe the recurring gateway to subscription lifecycle and
   payment events and point it to
   `/commerce-lms-entitlements/paypal/GATEWAY_ID` (the exact route currently
   configured in `commerce_lms_entitlements.routing.yml` is
   `/commerce-lms-entitlements/paypal/webhook/GATEWAY_ID`).
7. Grant the Authenticated role `view own commerce lms entitlements`, `cancel
   own commerce lms entitlements`, `change own commerce lms tier`, and `book
   own vip sessions`. The controllers additionally enforce record ownership
   and active-entitlement checks. Grant the restricted administration
   permissions only to trusted staff. Enable cron and monitor the audit
   command.
8. For VIP, install Recurring Events 3.x, configure paired VIP PayPal plans on
   each recurring offer, then configure the VIP hub and eligible event series
   at `/admin/commerce/config/lms-vip`. See `docs/VIP.md`.

## Lifecycle diagrams

### Recurring monthly, quarterly, or annual purchase

```text
checkout learner pane
  -> order data: existing UID or invitation ID
  -> PayPal subscription-create event
  -> PayPalPlanSubscriber validates offer + stores pending entitlement
  -> contributed checkout receives buyer approval
  -> contributed module stores paypal_subscription_id on order
  -> order update links ID to entitlement
  -> verified webhook is persisted and queued
  -> worker GETs PayPal subscription detail
  -> ACTIVE: Group membership grant
     SUSPENDED/EXPIRED: safe membership revoke
     CANCELLED: retain until access_through, then revoke
```

### Lifetime purchase

```text
checkout learner pane -> order data
  -> normal Commerce PayPal payment reaches completed state
  -> hook_entity_insert/update calls syncCompletedPayment()
  -> pending/active entitlement is created or reused
  -> Class memberships are granted
```

### New learner invitation

```text
unknown email -> random token (only SHA-256 hash is stored) -> email claim URL
  -> claimant must use the invited email
  -> create a validated account, or authenticate an existing account normally
  -> claim pending entitlements for that invitation
  -> immediately grant any already-active entitlement
```

Checkout pane values are nested below the pane's form parents. The learner
pane reads the submitted email from that nested value tree and refuses an
empty recipient. Repeated checkout submissions reuse an existing invitation
for an unchanged email instead of creating a new token and sending duplicate
mail.

## Data model

The module deliberately uses tables rather than a content entity: these rows
are an integration audit trail, are private implementation data, and are keyed
by an immutable Commerce order.

### `commerce_lms_entitlement`

One row per order (`order_id` is unique).

| Field | Meaning |
| --- | --- |
| `eid` | Local entitlement identifier used in membership/audit tables. |
| `offer_id`, `purchase_type` | Snapshot of the offer identity and recurring/lifetime model. |
| `purchaser_uid` | Account allowed to view/cancel this entitlement. |
| `learner_uid`, `invitation_id` | Current learner or unclaimed invitation. Exactly one is expected initially. |
| `order_id`, `payment_id` | Commerce audit links. `payment_id` is populated for lifetime payments. |
| `paypal_subscription_id` | Recurring PayPal object used to match webhooks. |
| `paypal_plan_id` | Last authoritative PayPal plan observed for the subscription. |
| `vip_selected`, `vip_active` | Requested checkout tier and currently paid/active VIP benefit. |
| `initial_capture_id`, `refund_id` | PayPal transaction IDs for the guarantee path. |
| `status` | Current local access state; see below. |
| `activated`, `access_through` | Unix timestamps for guarantee eligibility and deferred cancellation. |
| `guarantee_requested` | Timestamp recording that immediate revocation/refund was requested. |
| `created`, `changed` | Local audit timestamps. |

Status values currently written are:

| Status | Meaning and access effect |
| --- | --- |
| `pending` | Payment/subscription is not yet active; no membership grant. |
| `active` | PayPal says active, or a lifetime payment completed; grant membership. |
| `suspended` | PayPal suspended billing; revoke module-owned membership. |
| `cancelled` | Renewal cancelled. Keep access until `access_through`; reconciliation revokes at expiry. |
| `expired` | No remaining access; revoke module-owned membership. |
| `guarantee_refunded` | Immediate guarantee revocation succeeded and PayPal returned a refund ID. |
| `guarantee_refund_pending` | Immediate revocation occurred but refund recovery is required. |

### `commerce_lms_entitlements.offer.*`

Each offer is a configuration entity. Lifetime offers use
`payment_gateway_id`. Recurring offers use `paypal_environment` and separate
`paypal_sandbox_*` / `paypal_live_*` gateway, product, and plan values.
`billing_interval` records the expected monthly, quarterly, or annual cadence.
Legacy `payment_gateway_id` and `paypal_plan_id` values mirror the active
recurring mapping for backward compatibility.

### `commerce_lms_entitlement_membership`

One row per entitlement/Class/benefit. `benefit` distinguishes `base` from
`vip`; `membership_created = 1` means this module
created the Group membership. `active = 1` means this entitlement still
supports it. When revoking, the code first flips the row inactive, then checks
for any other active entitlement for the same user and Class. A Group
relationship is deleted only when no support remains and the history proves
that this module originally created it; an entirely manual membership has no
such ownership row and survives.

### `commerce_lms_plan_change`

One immutable audit row per requested PayPal revision. It records source and
target tiers/plans, only a hash of the browser state token, approval status,
and the next-billing effective time. `approval_pending` and `approved` are the
only unresolved states; `effective`, `abandoned`, and `failed` are terminal.

### `commerce_lms_vip_booking`

Maps an active Recurring Events registrant to its learner and site-timezone
calendar month. The learner/month unique key is the quota boundary. The
registrant entity remains authoritative for event capacity and administration.

### `commerce_lms_entitlement_event`

The PayPal event ID is the primary key, so a repeated verified delivery cannot
create duplicate work or access grants. `queued`, `processed`, and `failed`
describe local processing. The complete received JSON is retained for audit;
it is never used as the source of truth for access.

### `commerce_lms_entitlement_invitation`

Stores a UUID, normalized email, SHA-256 token hash, expiry, and claiming UID.
The plaintext 256-bit token exists only long enough to put it in the invitation
email. An invitation expires after 30 days.

## Source-file reference

| File | Responsibility |
| --- | --- |
| `commerce_lms_entitlements.info.yml` | Declares dependencies on Commerce, the PayPal modules, Group, and LMS Classes. |
| `commerce_lms_entitlements.install` | Defines audit/access/tier/booking tables; update `10011` adds dual PayPal mappings, `10012` adds VIP state, and `10013` adds campaign audit metadata. |
| `commerce_lms_entitlements.module` | Bridges Commerce entity events to the manager, queues reconciliation from cron, and supplies invitation mail text. |
| `services.yml` | Registers the manager, PayPal REST/catalog services, event subscribers, and log channel. |
| `routing.yml`, `links.menu.yml`, `permissions.yml` | Define the webhook, invitation, purchaser and administrator routes; the admin menu entry; and authorization gates. |
| `Entity/LmsOffer.php` | Config-entity definition for one variation-to-bundle mapping. |
| `Entity/LmsSubscriptionCampaign.php`, campaign form/list builder | Define reusable campaign behavior and per-offer introductory prices, cycles, and sandbox/live plan mappings. |
| `Form/OfferForm.php` | Administrator UI for dual PayPal mappings, live plan discovery/validation, active environment, expected cadence, and `COURSE_ID:CLASS_ID` parsing. |
| `CheckoutPane/LearnerPane.php` | Stores the chosen existing learner or creates/sends an invitation before payment approval. |
| `CheckoutPane/VipUpgradePane.php`, `VipOrderProcessor.php` | Store the order-bump choice and add its idempotent labeled recurring adjustment. |
| `CheckoutPane/SubscriptionCampaignPane.php`, `PromotionOffer/LmsSubscriptionCampaignOffer.php` | Validate/disclose coupon terms and adjust the Commerce total to the curated PayPal introductory charge. |
| `EventSubscriber/PaymentGatewaySubscriber.php` | Filters Commerce's available gateways so a valid LMS offer can use only its configured recurring or one-time gateway. |
| `EventSubscriber/PayPalPlanSubscriber.php` | Intercepts the contributed module’s subscription creation event, validates the order/offer/gateway, creates the pending entitlement, and injects the PayPal plan ID. |
| `Controller/PayPalWebhookController.php` | Public endpoint that verifies the PayPal transmission signature using the contributed SDK, deduplicates the event, queues work, and immediately responds. |
| `QueueWorker/PayPalWebhookWorker.php` | Loads a verified event, obtains the current subscription detail from PayPal, and applies it. Events that race ahead of the order link are requeued rather than lost. |
| `QueueWorker/ReconcileWorker.php` | Runs the manager’s expiry and remote-state reconciliation from cron. |
| `QueueWorker/LivePlanAuditWorker.php` | Re-fetches live PayPal mappings asynchronously from cron and logs invalid or missing mappings. |
| `EntitlementManager.php` | Central state machine, data access, offer/target validation, invitation handling, and safe Group membership grant/revoke logic. |
| `PayPalPlanCatalog.php` | Discovers live products/plans and validates status, quantity, trial, cadence, price, and currency against an offer. |
| `SubscriptionCampaignResolver.php`, `SubscriptionPlanSelection.php` | Resolve one supported coupon to an immutable, environment- and tier-specific campaign plan selection. |
| `PayPalSubscriptionOperations.php` | Direct PayPal REST adapter for catalog reads, plan details, revision, cancellation, and capture refunds using gateway-owned credentials. |
| `PlanChangeManager.php` and tier form/controller | Own the revision state token, PayPal re-consent return, and next-renewal transition. |
| `VipBookingManager.php` and VIP forms/controller | Enforce active learner access, capacity, cutoff, monthly quota, protected meeting display, and booking mail. |
| `patches/commerce_paypal_subscriptions-1.0.0-commerce-paypal-1.12-sdk-factory.patch` | Composer-managed local copy of the upstream issue patch correcting stale `commerce_paypal_subscriptions` 1.0.0 factory service arguments with Commerce PayPal 1.12/2.1.x. |
| `Form/ClaimInvitationForm.php` | Creates a validated invited-email account or sends an existing account through Drupal's normal login flow, then claims/grants pending access. |
| `Form/CancelEntitlementForm.php` | Owner-only regular cancellation and 40-day guarantee request. |
| `Controller/EntitlementController.php` | Purchaser-scoped status table and unrestricted-for-staff audit table. Both custom-table views use a zero cache maximum age so personalized or changed entitlement data cannot be reused. |
| `Drush/Commands/EntitlementCommands.php` | Read-only `drush commerce-lms-entitlements:audit` report for recovery work and current live plan validity. |

## Important methods in `EntitlementManager`

`offerForOrder()` is the first guardrail. It enforces the one-item/quantity-one
invariant, finds exactly one offer by variation ID, and calls `validateTargets`.
It throws a `DomainException`, letting checkout display a configuration/cart
error rather than creating ambiguous access.

`ensureEntitlement()` is idempotent because `order_id` is unique. It records a
pending row only after the learner pane has stored either an existing UID or an
invitation ID.

`linkPayPalSubscriptionFromOrder()` handles the hand-off from contributed
checkout. It also requeues matching stored webhook events to close the normal
race where PayPal posts before Drupal persists the subscription ID.

`syncCompletedPayment()` handles only lifetime offers. It requires both a
completed payment and Commerce's aggregate `Order::isPaid()` result before it
activates access. Commerce can refresh the order's paid total after the payment
entity hook, so the later order update also calls `syncPaidLifetimeOrder()`.
That method loads completed payments for the order and accepts only a payment
whose order and configured gateway match. Both paths converge on the same
idempotent activation method. Recurring offers deliberately ignore this path
because their billing state is PayPal subscription state, not the initial
Commerce payment state.

`applyRemoteSubscription()` maps PayPal `ACTIVE`, `SUSPENDED`, `CANCELLED`, and
`EXPIRED` to local state. It records initial capture and paid-through data,
then invokes `grant()` or `revoke()` as appropriate.

`reconcile()` has two jobs: remove cancelled access after paid-through expiry,
and poll all nonterminal recurring subscriptions to self-heal missed events.
Failures are logged per entitlement so one PayPal/API error does not stop the
rest of the batch.

`grant()` uses Group 3's group-level membership API: `Group::getMember()`
checks for an existing membership and `Group::addMember()` creates one when
needed. If a membership already exists, the module records support without
claiming ownership. An existing ledger row retains its original ownership
decision during repeated grants. If the Group membership is absent, the module
creates it and records ownership. Each Group mutation and its ledger mutation
share a database transaction. Safe revocation uses the corresponding
`Group::removeMember()` method only after the ownership and other-entitlement
checks pass; a removal failure rolls the ledger state back so reconciliation
can retry it. The implementation uses core's `Connection::startTransaction()`
and `SelectInterface::forUpdate()` APIs; production row-lock behavior must be
validated with the site's MariaDB driver because SQLite treats `forUpdate()`
as a no-op.

Invitation claiming conditionally updates only an unclaimed, unexpired row and
requires exactly one affected row. The claimed marker, learner assignment, and
active membership grants share one database transaction. A losing concurrent
claim or failed Group operation therefore cannot produce a partial claim.
After a successful new-account claim, the learner is logged into that newly
created account. An active entitlement redirects to `/courses`, where the
newly granted access is immediately usable. A pending entitlement instead
redirects to the learner account page and explains that access awaits
subscription payment activation. An already authenticated matching learner
follows the same status-sensitive redirect without starting a new session.
An anonymous claimant whose invited email already belongs to an account sees
no account-creation or claim controls; the form sends that claimant through
Drupal's normal login route and returns to the signed claim URL afterward.
If a purchaser or another user opens the invitation while authenticated, the
claim form compares that account's email address with the invitation before
showing a submit button. A mismatch instead provides a logout link whose
destination returns to the same signed invitation, allowing the intended
learner to create or claim the account without losing the token.

Purchaser ownership can be established after the pending entitlement is first
created. A later Commerce order update synchronizes its non-anonymous customer
ID into `purchaser_uid`. If an anonymous self-purchaser instead creates the
account through an invitation whose email matches the Commerce order email,
the verified claim binds both the order customer and the entitlement purchaser
to that account while also assigning it as learner. A different learner email
never changes purchaser ownership.
`revoke()` is the inverse, including the cross-entitlement support check.

## Webhook contract

Configure this endpoint for each contributed **subscription** gateway:

```text
POST /commerce-lms-entitlements/paypal/webhook/{commerce_payment_gateway ID}
```

The route is intentionally public because PayPal cannot authenticate as a
Drupal account. It is safe only because the controller requires the gateway’s
webhook ID and calls the contributed SDK’s server-side signature-verification
API with all PayPal transmission headers. Invalid JSON, wrong gateway plugin,
missing configuration, invalid signature, and verification failures return 4xx
and are logged. A successful duplicate returns success so PayPal stops retrying.

The webhook route converts its gateway-ID path component into a Commerce
payment-gateway entity before invoking the controller. Without that route
parameter conversion, PHP rejects the string argument before signature
verification and PayPal receives an HTTP 500 response.

Lifecycle webhook resources use their own resource ID as the subscription ID.
Payment-sale resources instead identify the subscription in
`billing_agreement_id`; their resource ID is the payment transaction and must
not be used for entitlement lookup. The worker re-extracts this value from the
saved payload, allowing it to repair events accepted by older module versions.

If PayPal delivers a verified lifecycle event before contributed checkout code
has copied the subscription ID to the entitlement, the worker leaves the event
in the module's event table with status `queued` and finishes its queue item.
The order-link hook enqueues the saved event after the link exists. It must not
immediately release the queue item, because a CLI queue runner can reclaim the
same item repeatedly until the command times out.

## Purchaser cancellation and guarantee details

The cancellation form first checks the row belongs to the current purchaser;
the route permission alone is not sufficient. For recurring purchases it calls
PayPal’s cancel endpoint, fetches the post-cancellation detail, and applies the
authoritative `access_through` time. For a lifetime purchase only an eligible
guarantee is offered.

The guarantee is eligible before the UTC activation timestamp plus 40 calendar
days. It revokes module-owned access immediately. The initial capture is then
refunded using PayPal’s refund endpoint. If PayPal returns a refund ID the row
becomes `guarantee_refunded`; any exception becomes
`guarantee_refund_pending` and is logged. Run the audit command to find those
cases and resolve/retry them through staff procedures.

## Operations and troubleshooting

Useful commands:

```bash
drush commerce-lms-entitlements:audit
drush cron
drush queue:run commerce_lms_entitlements_webhook
drush queue:run commerce_lms_entitlements_reconcile
drush queue:run commerce_lms_entitlements_live_plan_audit
```

After deploying the dual-mapping schema, run `drush updb`. Update hook `10011`
copies each legacy recurring gateway/plan pair into the environment indicated
by that gateway's current mode. Because the old model did not store cadence,
the hook recognizes `annual`/`year` and `quarter` in the offer ID or label and
otherwise defaults to monthly. Review the resulting interval before enabling
live checkout.

Update `10012` adds VIP fields, benefit-aware membership uniqueness, tier
revision history, and monthly booking ownership. It backfills existing
recurring rows with their base plan and does not grant VIP. Complete setup and
operational details are in `docs/VIP.md`.

The audit command fetches every configured live plan and reports unconfigured
or invalid mappings. It does not change Commerce prices, PayPal plans, active
checkout environment, or LMS targets.

For a suspected webhook problem, first inspect the configured gateway’s
webhook ID and event subscription in PayPal, then inspect Drupal logs and the
event table by event ID. Do not manually add/remove Group memberships to retry
an entitlement: doing so changes ownership semantics. Prefer fixing the PayPal
state and running reconciliation; only use a deliberate, audited staff remedy
for a failed guarantee refund.

## Testing boundaries

The required coverage is kernel tests for target validation, invitation claim,
event/order idempotency, and membership ownership; and functional tests using a
mocked PayPal client for approval, signature acceptance/rejection, duplicate
delivery, failure/recovery, cancellation expiry, and guarantee refund. Those
tests are not yet present or run in this repository. The module must be tested
against a bootstrapped site with the exact Group/LMS/Commerce versions before
production use.
