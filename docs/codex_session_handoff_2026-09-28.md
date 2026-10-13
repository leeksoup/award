# Codex Session Handoff — 2026-09-28

## Start Here

The repository is `/home/agentbot/code/award`, currently on `main` at:

```text
1606420 Fix VIP access test for PHPUnit 9
eb55172 Authorize VIP sessions through Hub membership
```

Before Drupal, Commerce, Group, LMS, or Drush API work, follow `AGENTS.md` and
consult the Drupal MCP at `https://mcp.tresbien.tech/mcp`. Consult the PayPal
MCP as well for PayPal-facing work.

Do not modify core or contributed modules. Preserve all unrelated worktree
changes listed below.

## Immediate Priorities

1. Rerun the corrected VIP access unit test on the bootstrapped dev site.
2. Deploy commits `eb55172` and `1606420` to staging and production if they
   have not already been deployed.
3. Rebuild caches and test `/vip-sessions` as a teacher, a VIP student, and a
   nonmember.
4. Help the user replace a student's old or broken invitation link. No
   replacement has yet been generated.

## VIP Sessions Access Change

### Problem

Purchasing students could open `/vip-sessions`, but a site administrator/LMS
teacher who was manually added to the VIP Hub received Access denied on both
staging and production.

The old implementation required both the route permission and an active
`commerce_lms_entitlement` row with `vip_active = 1`. It did not consider VIP
Hub membership. This excluded teachers and manually added test learners.

### Agreed Policy

Access requires both:

- Drupal permission `book own vip sessions`; and
- direct Group membership in either the Course or Class configured at
  `/admin/commerce/config/lms-vip`.

The entitlement system still grants and revokes the VIP Class membership for
purchasers. Administrators may manually add teachers or test learners to the
VIP Course or Class. A user outside both configured groups must be denied.

### Implementation

Commit `eb55172`:

- Added
  `web/modules/custom/commerce_lms_entitlements/src/VipGroupAccessChecker.php`.
- Registered the checker in
  `commerce_lms_entitlements.services.yml`.
- Changed `VipBookingManager::hasAccess()` to delegate to the membership
  checker instead of querying active VIP entitlements.
- Accepts membership in either configured group.
- Updated the module README and
  `docs/commerce_lms_entitlements_production_setup.md`.
- Added `VipGroupAccessCheckerTest.php`.

The initial test used PHPUnit's `#[DataProvider]` attribute. The site's
PHPUnit 9 runner ignored it and invoked the test with zero arguments. Commit
`1606420` replaced that attribute with the PHPUnit 9-compatible
`@dataProvider` annotation.

### Verification

Run on dev:

```bash
vendor/bin/phpunit -c web/core \
  web/modules/custom/commerce_lms_entitlements/tests/src/Unit/VipGroupAccessCheckerTest.php
```

The corrected test has not yet been reported as passing by the user.

After deployment:

```bash
drush cr
```

No update hook, database update, or configuration import is required for this
change. It adds a service definition and PHP classes only.

Confirm that the teacher role has the route permission, using the actual role
machine name:

```bash
drush role:perm:add lms_teacher 'book own vip sessions'
drush cr
```

Manual smoke tests:

- Teacher in configured VIP Course: allowed.
- Student in configured VIP Class: allowed.
- Member of both: allowed.
- User outside both configured groups: denied.
- Anonymous user: denied.

## Broken Student Invitation

The user reported that a student says her invitation link did not work. This
may be an invitation created before the recent invitation, recovery, and mail
fixes.

Invitation properties:

- The database stores only a SHA-256 hash of the plaintext token, so an old
  link cannot be reconstructed from the database.
- Invitations expire after 30 days.
- A claimed invitation must not be replaced. If `learner_uid` or
  `claimed_uid` is populated, direct the student to normal login/password
  reset instead.
- Generate absolute URLs with Drush's production `--uri`; otherwise CLI URL
  generation can produce `http://default/...`.

Find the entitlement at `/admin/commerce/lms-entitlements`, or inspect it by
order ID:

```bash
drush sql:query "
SELECT e.eid, e.order_id, e.status, e.learner_uid,
       e.invitation_id, i.email, i.claimed_uid,
       FROM_UNIXTIME(i.expires) AS expires
FROM commerce_lms_entitlement e
LEFT JOIN commerce_lms_entitlement_invitation i
  ON i.id = e.invitation_id
WHERE e.order_id = ORDER_ID;
"
```

If the entitlement is active, `learner_uid` is NULL, and the invitation is
unclaimed, replace `ENTITLEMENT_ID` and the production domain in this command.
It creates a fresh invitation for the order's stored learner email, atomically
attaches it to the entitlement, invalidates the old link, and prints the new
one:

```bash
drush --uri=https://PRODUCTION-DOMAIN php:eval '
$eid = ENTITLEMENT_ID;
$db = \Drupal::database();
$entitlement = $db->select("commerce_lms_entitlement", "e")
  ->fields("e")
  ->condition("eid", $eid)
  ->execute()
  ->fetchAssoc();

if (!$entitlement) {
  throw new \RuntimeException("Entitlement not found.");
}
if ($entitlement["status"] !== "active") {
  throw new \RuntimeException("Entitlement is not active.");
}
if ($entitlement["learner_uid"] !== NULL) {
  throw new \RuntimeException("Invitation was already claimed by UID " . $entitlement["learner_uid"] . ".");
}

$order = \Drupal::entityTypeManager()
  ->getStorage("commerce_order")
  ->load((int) $entitlement["order_id"]);
$learner = $order?->getData("commerce_lms_learner") ?: [];
$email = mb_strtolower(trim((string) ($learner["email"] ?? "")));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  throw new \RuntimeException("The order has no valid learner email.");
}

$old_id = $entitlement["invitation_id"];
if ($old_id !== NULL) {
  $claimed_uid = $db->select("commerce_lms_entitlement_invitation", "i")
    ->fields("i", ["claimed_uid"])
    ->condition("id", $old_id)
    ->execute()
    ->fetchField();
  if ($claimed_uid !== FALSE && $claimed_uid !== NULL) {
    throw new \RuntimeException("The invitation was already claimed by UID " . $claimed_uid . ".");
  }
}

$transaction = $db->startTransaction();
try {
  $manager = \Drupal::service("commerce_lms_entitlements.manager");
  $invitation = $manager->createInvitation($email);
  $update = $db->update("commerce_lms_entitlement")
    ->fields([
      "invitation_id" => $invitation["id"],
      "changed" => \Drupal::time()->getRequestTime(),
    ])
    ->condition("eid", $eid)
    ->isNull("learner_uid");
  if ($old_id === NULL) {
    $update->isNull("invitation_id");
  }
  else {
    $update->condition("invitation_id", $old_id);
  }
  if ($update->execute() !== 1) {
    throw new \RuntimeException("Entitlement changed while generating the invitation.");
  }
  if ($old_id !== NULL) {
    $db->delete("commerce_lms_entitlement_invitation")
      ->condition("id", $old_id)
      ->execute();
  }
  $url = \Drupal\Core\Url::fromRoute(
    "commerce_lms_entitlements.claim",
    ["token" => $invitation["token"]],
    ["absolute" => TRUE],
  )->toString();
  print "Replacement invitation for {$email}:\n{$url}\n";
}
catch (\Throwable $exception) {
  $transaction->rollBack();
  throw $exception;
}
'
```

Before sending the URL, verify that its host is the production domain and not
`default`. Sending the printed replacement manually is acceptable and avoids
depending on email delivery while troubleshooting.

## Current Checks And Limitations

For the VIP implementation:

- `php -l` passed for the new checker, modified booking manager, and unit
  test.
- `git diff --check` passed.
- `composer validate --no-check-publish` passed with warnings.
- Local PHPUnit and PHPCS were unavailable in the Codex checkout.
- Ruby was unavailable, so the YAML command in `AGENTS.md` could not run.

Composer currently warns that `composer.json` contains a duplicate
`conflict` key. This was pre-existing/unrelated to the VIP access commit and
was not modified during that work. Because duplicate JSON keys can cause the
earlier Twig conflict declaration to be ignored, review this separately before
the next Composer update.

## Worktree State To Preserve

At handoff time, the following unrelated changes were present and intentionally
left untouched:

```text
 M composer.json
 M composer.lock
?? docs/anu_to_lms_migration-handoff.md
?? docs/anu_to_lms_migration_plan.md.rej
?? docs/anu_to_lms_migration_pseudocode_spec.md.rej
?? docs/anu_to_lms_real_database_runbook.md.orig
?? docs/codex_sandbox_handoff.md
?? docs/drupal_11_upgrade_handoff.md
?? web/modules/contrib/commerce_paypal_subscriptions/
```

Do not delete, reset, overwrite, or include these in an unrelated commit.

## Relevant Background Documents

- `docs/commerce_lms_entitlements_production_setup.md`
- `docs/commerce_lms_entitlements_security_remediation_plan.md`
- `docs/commerce_subscription_coupon_implementation_plan.md`
- `docs/commerce_lms_30_percent_vip_campaign.md`
- `docs/drupal_11_upgrade_handoff.md`
- `AGENTS.md`

## Deployment Notes

The user commonly deploys with rsync and may not have Composer on the remote
server. Build dependency trees locally with:

```bash
composer install --no-dev --optimize-autoloader
```

The project pins Twig to 3.29.0 because Twig 3.30 caused a Drupal 10.6 WSOD.
Verify deployed Twig without Composer using:

```bash
drush php:eval 'print \Composer\InstalledVersions::getPrettyVersion("twig/twig") . PHP_EOL;'
```

Avoid a blind full `drush cim` when production has wanted configuration shown
as `Only in DB`; a full import would delete those objects. Reconcile the
canonical sync directory deliberately first, or use a narrowly scoped partial
import when appropriate.
