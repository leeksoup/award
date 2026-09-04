# Commerce LMS Entitlements: architecture and code guide

This module turns a Commerce purchase into controlled membership of one or more
LMS Classes. It is deliberately an **access layer**, not a replacement payment
gateway or subscription engine.

The two contributed modules own PayPal checkout:

- `commerce_paypal` owns a one-time PayPal Checkout payment. This is used for
  the lifetime variation.
- `commerce_paypal_subscriptions` owns PayPal subscription approval and stores
  the approved PayPal subscription ID on the Commerce order. This is used for
  monthly and annual variations.

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

## Setup sequence

1. Install and configure `commerce_paypal` and
   `commerce_paypal_subscriptions`. The recurring gateway must be the
   contributed `paypal_checkout_subscriptions` plugin and include the PayPal
   webhook ID, client ID, client secret, and `test` or `live` mode.
2. Create the PayPal monthly and annual plans in PayPal. No plan is required
   for the lifetime variation.
3. Create three Commerce product variations: monthly recurring, annual
   recurring, and lifetime one-time.
4. Configure the Commerce checkout flow to enable the **Learner** pane from
   `src/Plugin/Commerce/CheckoutPane/LearnerPane.php`.
5. Create one offer per variation at
   `/admin/commerce/config/lms-offers`. Give recurring offers their PayPal plan
   IDs and all offers their sole allowed payment-gateway ID. Enter each bundle
   item as `COURSE_ID:CLASS_ID` in its intended order.
6. In PayPal, subscribe the recurring gateway to subscription lifecycle and
   payment events and point it to
   `/commerce-lms-entitlements/paypal/GATEWAY_ID` (the exact route currently
   configured in `commerce_lms_entitlements.routing.yml` is
   `/commerce-lms-entitlements/paypal/webhook/GATEWAY_ID`).
7. Assign the listed permissions. Enable cron and monitor the audit command.

## Lifecycle diagrams

### Recurring monthly or annual purchase

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
  -> create/reuse account
  -> claim pending entitlements for that invitation
  -> immediately grant any already-active entitlement
```

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

### `commerce_lms_entitlement_membership`

One row per entitlement/Class. `membership_created = 1` means this module
created the Group membership. `active = 1` means this entitlement still
supports it. When revoking, the code first flips the row inactive, then checks
for any other active entitlement for the same user and Class. Only a row with
`membership_created = 1` and no other support can cause a Group relationship
deletion.

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
| `commerce_lms_entitlements.install` | Defines the four audit/access tables above. Existing sites need an update hook before this module is introduced after installation. |
| `commerce_lms_entitlements.module` | Bridges Commerce entity events to the manager, queues reconciliation from cron, and supplies invitation mail text. |
| `services.yml` | Registers the manager, PayPal cancellation/refund adapter, event subscriber, and log channel. |
| `routing.yml`, `links.menu.yml`, `permissions.yml` | Define the webhook, invitation, purchaser and administrator routes; the admin menu entry; and authorization gates. |
| `Entity/LmsOffer.php` | Config-entity definition for one variation-to-bundle mapping. |
| `Form/OfferForm.php` | Administrator UI. Parses `COURSE_ID:CLASS_ID`; entity existence and parent relationship are checked at actual use. |
| `CheckoutPane/LearnerPane.php` | Stores the chosen existing learner or creates/sends an invitation before payment approval. |
| `EventSubscriber/PayPalPlanSubscriber.php` | Intercepts the contributed module’s subscription creation event, validates the order/offer/gateway, creates the pending entitlement, and injects the PayPal plan ID. |
| `Controller/PayPalWebhookController.php` | Public endpoint that verifies the PayPal transmission signature using the contributed SDK, deduplicates the event, queues work, and immediately responds. |
| `QueueWorker/PayPalWebhookWorker.php` | Loads a verified event, obtains the current subscription detail from PayPal, and applies it. Events that race ahead of the order link are requeued rather than lost. |
| `QueueWorker/ReconcileWorker.php` | Runs the manager’s expiry and remote-state reconciliation from cron. |
| `EntitlementManager.php` | Central state machine, data access, offer/target validation, invitation handling, and safe Group membership grant/revoke logic. |
| `PayPalSubscriptionOperations.php` | Small direct PayPal REST adapter for cancellation and capture refund, which the contributed checkout SDK does not expose. |
| `Form/ClaimInvitationForm.php` | Creates/reuses only the account matching the invited email, then claims/grants pending access. |
| `Form/CancelEntitlementForm.php` | Owner-only regular cancellation and 40-day guarantee request. |
| `Controller/EntitlementController.php` | Purchaser-scoped status table and unrestricted-for-staff audit table. |
| `Drush/Commands/EntitlementCommands.php` | Read-only `drush commerce-lms-entitlements:audit` count of failed webhooks and refund recovery work. |

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

`syncCompletedPayment()` handles only lifetime offers. It intentionally ignores
recurring offers because their billing state is PayPal subscription state, not
the initial Commerce payment state.

`applyRemoteSubscription()` maps PayPal `ACTIVE`, `SUSPENDED`, `CANCELLED`, and
`EXPIRED` to local state. It records initial capture and paid-through data,
then invokes `grant()` or `revoke()` as appropriate.

`reconcile()` has two jobs: remove cancelled access after paid-through expiry,
and poll all nonterminal recurring subscriptions to self-heal missed events.
Failures are logged per entitlement so one PayPal/API error does not stop the
rest of the batch.

`grant()` uses Group 3’s `GroupMembership::loadByGroupAndUser()`. If a
membership already exists, the module records support without claiming
ownership. If absent, it creates the Group membership and records ownership.
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
```

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
