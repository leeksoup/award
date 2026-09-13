# Commerce LMS Entitlements security remediation plan

Date: 2026-09-11

Module: `web/modules/custom/commerce_lms_entitlements`

## Purpose

This document records the results of a read-only security review of the
Commerce LMS Entitlements module and provides an implementation and validation
plan. The module should not be relied on as the production authorization
boundary for paid LMS access until the critical and high-priority findings are
resolved and tested against a bootstrapped Drupal/Commerce site.

The review covered:

- entitlement and Group membership grants and revocations;
- invitation-based account creation and claiming;
- purchaser authorization and personalized output;
- lifetime and recurring payment state;
- PayPal webhook verification and processing;
- base/VIP plan changes and benefit reconciliation;
- VIP booking authorization and capacity controls;
- Drupal input, output, routing, permission, and cache practices; and
- available automated and static checks.

No production database was available for this review. Existing entitlement and
membership data therefore has not been classified or repaired.

## Executive priority

Complete these items before production launch, in this order:

1. Correct membership ownership preservation and transactional revocation.
2. Remove the existing-account authentication bypass from invitation claims.
3. Prevent cross-user caching of purchaser entitlement pages.
4. Require a fully paid Commerce order before granting lifetime access.
5. Fail closed on unexpected PayPal plans and correctly reconcile VIP state.
6. Define and implement target-bundle reconciliation behavior.
7. Add regression tests for every access-changing state transition.

Do not bulk-edit existing `membership_created` values. A value of `0` can mean
either a legitimate pre-existing/manual membership or a module-created
membership whose ownership flag was incorrectly overwritten. Those cases must
be distinguished using backups, Group relationship history, entitlement
timestamps, and operational knowledge.

## First implementation slice

Begin with the membership-ownership defect because it affects the fundamental
paid-access boundary. Use this sequence:

1. Take a current database backup before deploying or repairing entitlement
   data.
2. Add a regression test that grants one entitlement, grants it again through
   reconciliation, and then revokes it.
3. Confirm that the test fails against the original implementation because the
   repeated grant changes `membership_created` from `1` to `0`.
4. Correct `grantTargets()` so an existing ledger row retains its ownership
   value, while a missing Group membership created during the current grant is
   recorded as module-owned.
5. Correct `revokeBenefit()` so each membership and ledger mutation is
   transactional and a failed `removeMember()` remains retryable.
6. Add companion coverage for manual memberships, overlapping entitlements,
   removal failure, and successful retry.
7. Run the existing-data ownership audit before production deployment. Do not
   automatically repair ambiguous rows.

The primary regression must prove this complete transition:

```text
first grant:
  Group membership exists
  membership_created = 1
  active = 1

repeated grant:
  Group membership still exists
  membership_created remains 1
  active remains 1

revocation:
  Group membership is removed
  ledger row becomes inactive
```

The implementation should isolate membership mutation from unrelated payment
and invitation flows so its database transaction behavior can be tested
directly.

Implementation status (2026-09-12): the membership operations have been
isolated in `EntitlementMembershipManager`; ownership-preserving grants and
transactional revocations are implemented. A kernel regression suite covers
the primary transition and the manual, overlapping-entitlement, base/VIP, and
failure/retry cases. The suite was run by the site owner against MariaDB with
Drupal 10.6.15 and PHPUnit 9.6.36; all 5 tests passed with 32 assertions.

Drupal API validation recorded on 2026-09-12:

- The Drupal MCP confirmed that Group's `getMember()` returns `FALSE` when the
  account is not a member, matching the truth-value check in the implementation.
- The Drupal MCP confirmed that Group's `addMember()` and `removeMember()`
  accept `UserInterface`, which is now enforced by the membership manager.
- The Drupal MCP confirmed that core provides `Connection::startTransaction()`
  and `SelectInterface::forUpdate()` on the supported database API.
- The Drupal MCP confirmed that SQLite intentionally treats `forUpdate()` as a
  no-op. The kernel suite can therefore run with SQLite, but concurrency and row
  locking must also be exercised on the site's MariaDB staging environment.
- Drupal 10.6 core source confirms that `EntityStorageInterface::load($id)` has
  no native return type, so the kernel-test storage callbacks are compatible
  with that interface.

## Critical remediation

### 1. Preserve membership ownership and make revocation retryable

Affected code:

- `src/EntitlementMembershipManager.php::grantTargets()`
- `src/EntitlementMembershipManager.php::revokeBenefit()`
- `src/EntitlementManager.php` grant and revocation callers
- all active subscription reconciliation and webhook paths that call `grant()`

Original failure:

`grantTargets()` writes `membership_created` through a database `merge()` on
every grant. The first grant records `1` and creates the Group membership. A
later active webhook or reconciliation sees that the membership now exists and
overwrites the same ledger row with `0`. Revocation then treats the membership
as manually created and leaves it in place.

Revocation also marks a ledger row inactive before `removeMember()` succeeds.
If Group membership removal throws, the inactive row prevents a later retry.

Implementation requirements:

1. Load the existing entitlement/Class/benefit ledger row explicitly.
2. Determine whether the Group membership exists.
3. If the membership exists:
   - reactivate the ledger row if necessary;
   - preserve its existing `membership_created` value; and
   - never change an existing ownership value from `1` to `0`.
4. If the membership does not exist:
   - create it through `Group::addMember()`; and
   - record `membership_created = 1`, because this invocation created it.
5. Wrap the membership mutation and ledger mutation in a database transaction.
6. During revocation, ensure a failed `removeMember()` rolls the ledger state
   back so reconciliation can retry.
7. Preserve the rule that a genuinely manual membership is never removed.
8. Preserve access while another active entitlement supports the same user and
   Class.

Required tests:

- first grant creates membership and records ownership;
- repeated active grants preserve ownership;
- revocation removes a module-created membership;
- revocation preserves a manual membership;
- one entitlement ending does not remove access supported by another;
- removal failure leaves retryable ledger state;
- a later successful retry removes access;
- suspension, expiry, immediate guarantee revocation, and paid-through
  cancellation all use the corrected behavior; and
- base and VIP benefits sharing a Class do not remove each other's access.

Existing-data audit:

- report all entitlement membership rows with `membership_created = 0`;
- report whether the corresponding Group membership currently exists;
- report entitlement status, activation time, ledger creation time, Class, and
  learner;
- compare suspect records to a database backup from before repeated
  reconciliation, when available; and
- prepare a reviewed, explicit list of rows to correct. Do not infer ownership
  from `membership_created = 0` alone.

### 2. Require normal authentication for an existing invited account

Affected code:

- `src/Form/ClaimInvitationForm.php`
- `src/EntitlementManager.php::claimInvitation()`

Current failure:

When an anonymous invitation claimant uses an email that already belongs to a
Drupal account, the form loads that account and calls Drupal's login finalizer
without validating its password or account status. The invitation token thus
acts as a full login token for the existing account rather than only authorizing
the course invitation claim.

Implementation requirements:

1. During form build, detect whether the invited email belongs to an existing
   account.
2. For an existing account:
   - do not show account-creation username/password fields;
   - direct the claimant to Drupal's normal login route;
   - preserve the invitation claim URL as the post-login destination; and
   - after login, require the current account's normalized email to match the
     invitation email.
3. Do not call `LoginFinalizer::finalizeLogin()` for a pre-existing account.
4. Let Drupal's normal login flow enforce password validation, account status,
   flood protection, and any installed MFA or authentication policies.
5. For a genuinely new account:
   - validate the username, email, password, and complete user entity;
   - refuse username or email collisions;
   - save only after validation succeeds; and
   - finalize login only for the newly created, active account.
6. Claim the invitation atomically. The update that sets `claimed_uid` must
   require `claimed_uid IS NULL` and verify that exactly one row was changed.
7. Keep the email match check in `EntitlementManager` as a defense-in-depth
   invariant, not only in the form.

Required tests:

- anonymous claim creates and logs in a valid new account;
- an invitation for an existing account requires normal login;
- incorrect password, blocked account, and mismatched account are rejected;
- a forwarded token cannot log into a different or existing account;
- expired and previously claimed tokens are rejected;
- concurrent claim attempts produce one winner; and
- active and pending entitlements produce the correct post-claim status.

Implementation status (2026-09-12): the claim form now routes an existing
invited-email account through Drupal's normal login form and retains the signed
claim URL as its destination. Only a newly created, entity-validated account is
passed to `LoginFinalizer`. The claim update requires a matching unexpired
token, `claimed_uid IS NULL`, and exactly one affected row. Kernel coverage for
one-winner, wrong-email, and expired-token claim invariants was run by the site
owner against MariaDB with Drupal 10.6.15 and PHPUnit 9.6.36; all 3 tests passed
with 10 assertions.

## High-priority remediation

### 3. Make personalized entitlement pages uncacheable or correctly varied

Affected code:

- `src/Controller/EntitlementController.php::mine()`
- `src/Controller/EntitlementController.php::admin()`

Current failure:

The purchaser page queries by the current UID but declares neither the `user`
cache context nor a zero maximum age. Users with identical permissions can
therefore receive cached output generated for another purchaser. The custom
database tables also have no cache tags that are invalidated when their rows
change, so both purchaser and administrative output can remain stale.

Implementation requirements:

1. As the immediate safe fix, add `#cache['max-age'] = 0` to both tables/pages.
2. If caching is later desired:
   - vary purchaser output by the `user` cache context;
   - introduce explicit entitlement cache tags;
   - invalidate them on every entitlement, membership, plan-change, invitation,
     event, and booking mutation; and
   - add appropriate dependencies for referenced users, offers, and orders.

Required tests:

- two purchasers with the same roles never see each other's records;
- entitlement changes appear immediately;
- an administrator sees current status; and
- response cache headers contain the intended contexts/max-age.

### 4. Grant lifetime access only after the order is fully paid

Affected code:

- `src/EntitlementManager.php::syncCompletedPayment()`

Current failure:

The method grants lifetime access when any associated Commerce payment reaches
`completed`. It does not require the aggregate Commerce order to be fully paid.
Commerce supports partial payments and provides `Order::isPaid()` for this
determination.

Implementation requirements:

1. Require the associated order to report `isPaid()` before activation.
2. Retain the current one-item, quantity-one, offer, purchase-type, and configured
   gateway checks.
3. Ensure the payment belongs to the order being activated.
4. Make repeated payment/order updates idempotent.

Required tests:

- partial completed payment does not grant access;
- full aggregate payment grants access exactly once;
- payment on another order cannot activate the entitlement;
- refunded, voided, failed, and pending payments do not grant access; and
- a legitimate fully paid lifetime checkout still succeeds.

## Payment and benefit integrity

### 5. Fail closed on unexpected PayPal subscription plans

Affected code:

- `src/EntitlementManager.php::applyRemoteSubscription()`
- `src/EntitlementManager.php::finalizeDuePlanChange()`
- `src/Plugin/QueueWorker/PayPalWebhookWorker.php`
- `src/Drush/Commands/EntitlementCommands.php`

Current failure:

An `ACTIVE` subscription grants base access without requiring its remote
`plan_id` to match the entitlement's approved plan, a valid pending tier change,
or the configured offer and gateway environment. The audit command reports a
mismatch, but runtime processing continues to grant access. An unexpected move
back to a base plan also does not necessarily clear `vip_active` or revoke the
VIP benefit.

Implementation requirements:

1. Confirm that the fetched remote subscription ID equals the entitlement's
   stored subscription ID.
2. Build the allowed plan set from the entitlement's current approved plan and
   a valid unresolved tier change.
3. Verify that the configured gateway is appropriate for the offer and its
   sandbox/live environment.
4. Refuse to grant or expand access when the remote plan is outside the allowed
   set.
5. Record a recoverable mismatch state and log sufficient identifiers for staff
   without logging credentials or unnecessary customer data.
6. Correctly activate or revoke VIP only after the expected plan and successful
   billing boundary are confirmed.
7. Decide explicitly whether base access remains during a plan mismatch. The
   safest default is to preserve already-paid access only through a known
   paid-through boundary while preventing any new grant or upgrade.

Required tests:

- expected base and VIP plans activate their corresponding benefits;
- unknown, wrong-environment, or wrong-product plans do not grant access;
- approved upgrade and downgrade transitions occur only at the intended paid
  boundary;
- abandoned or superseded changes cannot alter access; and
- reconciliation repairs missed valid events without accepting invalid plans.

### 6. Define immutable or reconcilable offer targets

Affected code:

- `src/EntitlementManager.php::grant()`
- `src/EntitlementManager.php::validateTargets()`
- offer configuration and VIP hub configuration

Current failure:

Every active reconciliation loads the offer's current Course/Class targets.
Adding a target grants it to existing subscribers, while removing a target does
not revoke the old membership. Changing the VIP hub has the same accumulation
risk.

Choose and document one model:

1. Immutable purchase snapshot:
   - store the ordered Course/Class target set on the entitlement at purchase;
   - continue honoring that snapshot for the entitlement's life; and
   - use a deliberate migration when products must change.
2. Live bundle reconciliation:
   - compute desired targets from current offer configuration;
   - compare them with active ledger rows;
   - grant missing targets; and
   - transactionally revoke removed targets using the corrected ownership rules.

The immutable snapshot is safer for an auditable purchased product. The live
model may be appropriate if the business promise is that subscribers always
receive the current bundle, but removal behavior must still be explicit.

Required tests:

- adding, removing, or replacing a target has the documented effect;
- old targets never remain accidentally accessible;
- target changes cannot remove a manual membership; and
- VIP hub changes do not accumulate unauthorized hub memberships.

## Medium-priority hardening

### 7. Distinguish duplicate webhook events from storage failures

Affected code:

- `src/EntitlementManager.php::queueEvent()`
- `src/Controller/PayPalWebhookController.php`

Implementation requirements:

- catch only the database exception that represents an existing event primary
  key;
- optionally verify that the existing row uses the same event identity;
- allow schema, connection, encoding, and other storage failures to propagate
  as server errors so PayPal can retry;
- impose a reasonable request-body limit before JSON decoding; and
- add reverse-proxy or application rate limiting to the public verification
  endpoint.

Tests must cover a duplicate event, database failure, malformed JSON, invalid
signature, oversized body, unknown gateway, and a successful delivery.

### 8. Enforce VIP registrant policy below the form layer

Affected code:

- `commerce_lms_entitlements.module::commerce_lms_entitlements_form_alter()`
- `src/VipBookingManager.php`

The current form alteration blocks recognized native registrant forms for
non-staff users, but hiding a form is not a complete authorization boundary.
Alternate routes or enabled APIs must not be able to create, move, or delete a
registrant for a protected VIP series without the entitlement, quota, capacity,
cutoff, ownership ledger, and locking checks.

Implementation requirements:

- enforce the restriction through entity access and/or presave/predelete
  validation for configured VIP series;
- allow the module's booking manager and explicitly authorized staff workflow;
- review REST and other entity endpoints for `registrant`; and
- retain the form alteration only as a usability measure.

### 9. Serialize financial state transitions

Add locks or equivalent uniqueness/state-transition protection around:

- guarantee refunds;
- subscription cancellation;
- creation of unresolved tier changes; and
- tier-change completion/abandonment.

Repeated submissions and concurrent requests must not produce two refunds, two
PayPal revisions, or contradictory local states. Re-check entitlement ownership
and current state after acquiring the lock.

### 10. Reduce invitation-email abuse

The checkout pane sends invitations before payment approval. An unpaid checkout
can therefore generate unsolicited mail to an arbitrary address.

Consider:

- deferring invitation delivery until payment/subscription activation;
- applying Drupal flood control per source and destination address;
- expiring and deleting abandoned invitations; and
- ensuring a checkout rebuild cannot send additional mail for the same order and
  learner selection.

## Lower-priority Drupal hardening

1. Mark these permissions with `restrict access: true`:
   - `administer commerce lms offers`
   - `administer commerce lms entitlements`
2. Use `#plain_text` for the invitation email and other values that are intended
   to contain no markup.
3. Continue using translation placeholders for dynamic output.
4. Show generic user-facing messages for unexpected exceptions; retain detailed
   exception information only in restricted logs.
5. Establish retention periods for:
   - complete PayPal webhook payloads;
   - expired or claimed invitations and email addresses;
   - completed/abandoned plan changes; and
   - old operational errors.
6. Review whether complete webhook payloads are necessary. Prefer storing the
   minimum identifiers and status evidence required for audit and replay.
7. Add explicit HTTP timeouts to direct PayPal REST operations if the site's
   global HTTP client configuration does not already provide appropriate
   connect and total timeouts.
8. Continue to keep PayPal credentials in the payment gateway rather than
   duplicating them. For production, ensure secrets are sourced from protected
   configuration/Key management and are not committed in configuration exports.

## Existing strengths to preserve

- Drupal's parameterized database query builder is used consistently; no raw
  SQL concatenation was identified.
- User-initiated mutations use Form API and its normal CSRF protections.
- Purchaser ownership is checked again during cancellation and tier changes.
- PayPal webhook signatures are verified before persistence and queuing.
- Current subscription detail is fetched from PayPal rather than treating the
  webhook body as billing truth.
- Invitation and tier-change tokens use 256-bit randomness and are stored only
  as hashes.
- Direct PayPal API hosts are fixed and identifiers are URL encoded.
- VIP booking uses entitlement checks, locks, a database uniqueness constraint,
  capacity checks, cutoff checks, and current-user ownership.
- Payment gateway filtering and offer validation fail closed in the normal
  checkout path.

## Test plan

Add automated coverage before deployment:

### Unit tests

- offer and plan mapping validation;
- PayPal status/plan-to-local-state mapping;
- target-set comparison;
- invitation normalization and state rules; and
- membership ownership transition decisions.

### Kernel tests

- ledger and Group membership transactions;
- repeated grants and revocations;
- multiple entitlements supporting one membership;
- manual membership preservation;
- invitation claim concurrency;
- entitlement and event database uniqueness; and
- cache metadata on purchaser-specific render arrays.

### Functional tests

- new-user and existing-user invitation journeys;
- blocked account and wrong-account behavior;
- purchaser-only cancellation and tier changes;
- two purchasers with identical roles cannot see each other's data;
- native VIP registrant routes cannot bypass policy; and
- permission boundaries for purchaser, learner, entitlement administrator, and
  offer administrator.

### Commerce/PayPal integration tests on staging

- partial and full lifetime payments;
- recurring base and VIP activation;
- webhook duplicates, races, failures, and retries;
- missed webhook recovery through reconciliation;
- suspension, expiration, cancellation with paid-through access, and recovery;
- guarantee refund success, failure, retry, and double-submit behavior;
- tier upgrade/downgrade approval, abandonment, and renewal boundary;
- remote plan mismatch and wrong-environment behavior;
- target bundle and VIP hub changes; and
- membership removal verified through both Group membership and LMS `view` and
  `take` access checks.

## Deployment sequence

1. Take a database backup and preserve current entitlement, membership, Group
   relationship, webhook event, invitation, plan-change, and booking tables.
2. Deploy the code fixes with schema/update hooks only where storage changes are
   required.
3. Run database updates and rebuild caches.
4. Run a read-only entitlement/membership ownership report.
5. Manually classify ambiguous existing `membership_created = 0` rows.
6. Apply a reviewed repair list through an idempotent Drush command or update
   routine with dry-run output. Do not use a blanket SQL update.
7. Run reconciliation.
8. Verify cancelled, expired, suspended, active, lifetime, recurring, base, and
   VIP examples individually.
9. Verify LMS access with actual learner accounts, not UID 1 or an administrator.
10. Run the full staging test matrix before enabling live checkout or webhooks.
11. Monitor failed webhook events, refund recovery, plan mismatches, unexpected
    memberships, and access after cancellation.

## Required repository checks

Run the applicable checks after implementation:

```bash
git diff --check
find web/modules/custom/commerce_lms_entitlements -name '*.php' -print0 \
  | xargs -0 -n1 php -l
composer validate --no-check-publish
```

Also run Drupal coding standards, PHPStan, and PHPUnit when installed. This
review environment did not contain PHPCS, PHPStan, PHPUnit, Ruby, or a
`composer.lock`, so those checks and `composer audit --locked` could not be
completed here.

## Review references

- Drupal secure coding: https://www.drupal.org/docs/administering-a-drupal-site/security-in-drupal/writing-secure-code-for-drupal
- Drupal cache contexts: https://www.drupal.org/docs/develop/drupal-apis/cache-api/cache-contexts
- Drupal render-array cacheability: https://www.drupal.org/docs/drupal-apis/render-api/cacheability-of-render-arrays
- Drupal roles and restricted permissions: https://www.drupal.org/docs/roles-and-permissions

The Drupal MCP source index was also used to confirm core's authentication
sequence, `#plain_text` rendering practice, restricted administrative
permissions, and Commerce's aggregate `Order::isPaid()` API.
