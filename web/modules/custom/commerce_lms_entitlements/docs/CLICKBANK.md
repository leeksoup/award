# ClickBank lifetime-course pilot

This integration is disabled by default and supports exactly one non-recurring ClickBank product at quantity one. It does not create a Commerce order or PayPal payment. ClickBank is the merchant/receipt/refund system; Drupal grants an existing lifetime LMS offer to the learner chosen before checkout. A buyer does not need a Drupal account. The site's PayPal 40-day guarantee does not apply to these purchases; use [ClickBank's return policy](https://support.clickbank.com/en/articles/10535349-clickbank-s-return-and-subscription-cancellation-policy).

## Set up

1. Create and approve the ClickBank product and pitch page. Configure the ClickBank confirmation/thank-you URL as `/courses/clickbank/thanks`. Configure INS version 8 URL as `/commerce-lms-entitlements/clickbank/ins` (HTTPS). Request INS access if the field is unavailable.
2. Create a ClickBank API key with **Order Read** permission. Put the INS secret and API key in the site's private `settings.php` or secret-management environment, never in config export:

   ```php
   $settings['commerce_lms_entitlements_clickbank_ins_secret'] = getenv('CLICKBANK_INS_SECRET');
   $settings['commerce_lms_entitlements_clickbank_api_key'] = getenv('CLICKBANK_API_KEY');
   // Only on staging when testing ClickBank TEST_SALE / TEST_RFND:
   $settings['commerce_lms_entitlements_clickbank_allow_test_transactions'] = TRUE;
   ```

3. Map the ClickBank vendor nickname, exact product item number/SKU, and existing lifetime `commerce_lms_offer` ID in `commerce_lms_entitlements.clickbank`. Keep production `enabled: false` until a staging test succeeds. Confirm that the offer's Course/Class targets and learner invitation mail work. For example, on a bootstrapped staging site:

   ```bash
   drush cset commerce_lms_entitlements.clickbank vendor YOUR_VENDOR -y
   drush cset commerce_lms_entitlements.clickbank sku YOUR_ITEM_NUMBER -y
   drush cset commerce_lms_entitlements.clickbank offer_id YOUR_LIFETIME_OFFER -y
   # Staging only, for ClickBank TEST_SALE / TEST_RFND transactions:
   drush cset commerce_lms_entitlements.clickbank allow_test_transactions true --input-format=yaml -y
   # Enable on staging for the test purchase; enable production only at launch:
   drush cset commerce_lms_entitlements.clickbank enabled true --input-format=yaml -y
   ```

   Keep `allow_test_transactions: false` and omit the staging-only settings gate in production. Both switches must be on for a test sale to grant access. Export the intended non-secret configuration into the site's sync directory before deployment, then check `drush config:status`. Do not copy the secret or API key into YAML.
4. Add a CTA on the approved pitch page that links to `/courses/clickbank/checkout`. Affiliates must link through ClickBank HopLinks to the pitch page; the checkout form preserves attribution by redirecting to ClickBank's payment link in the same browser. The form sends an opaque `v1` token, not an email address.
5. Run `drush updb -y`, `drush cr`, and import the intended config before enabling. Verify the active ClickBank config and settings. ClickBank test transactions use distinct transaction types; the staging-only flag permits them. Enable production checkout only after a staging purchase with separate buyer and learner emails.

## Fulfillment and recovery

INS decrypts and persists an event before acknowledging ClickBank. Queue workers fetch the receipt from ClickBank Orders API and require the configured vendor, SKU, one non-recurring item at quantity one, sale/reversal type, and a valid precheckout learner intent. No browser return or unverified INS payload grants access. A missing/expired intent is marked for staff review; it never falls back to buyer email. Refunds and chargebacks revoke only memberships created by that entitlement; a delayed sale cannot reactivate a reversed receipt.

Run cron so the event, receipt, and reconciliation queues process. The reconciliation worker lists the last three days of vendor orders and queues each receipt for verification. A temporary API outage leaves an event failed and its queue item available for retry; operators can run:

```bash
drush commerce-lms-entitlements:clickbank-audit
drush commerce-lms-entitlements:clickbank-retry RECEIPT
drush commerce-lms-entitlements:clickbank-reconcile --days=7
drush queue:run commerce_lms_clickbank_receipt
```

An audit status of `review` requires staff to compare the receipt and learner choice with ClickBank and the purchaser. Do not fabricate a checkout intent or change the stored learner without authorization. If an order cannot be verified or is outside the one-item lifetime scope, fulfill it manually only after a documented policy decision.

## Staging acceptance checklist

- Affiliate HopLink to pitch page, CTA to learner form, ClickBank test payment, correct affiliate in ClickBank reporting, and distinct buyer/learner emails.
- Existing learner account gets access; a new learner receives one invitation and can claim it. Confirm Class membership ownership and actual `view`/`take` access.
- Duplicate INS and repeated receipt retries produce one entitlement/invitation; malformed INS and wrong vendor/SKU grant nothing.
- Simulate Orders API outage, recover with retry, then refund/chargeback and verify only entitlement-owned memberships are removed.
- Verify an expired/missing intent is visible to staff but never grants. Verify the thank-you page itself creates no entitlement.
- Compare ClickBank report against `clickbank-audit` after at least three days of cron, including a missed-INS scenario.

This repository checkout does not have a bootstrapped Drupal site or ClickBank credentials; none of these external checks should be reported as passed until actually run on staging.

## API references

- [INS version 8](https://support.clickbank.com/en/articles/10535147-instant-notification-service-ins)
- [Orders API](https://support.clickbank.com/en/articles/10535407-orders-api)
- [Payment links](https://support.clickbank.com/en/articles/10535167-how-to-create-a-payment-link)
- [Seller variables](https://support.clickbank.com/en/articles/10535222-query-string-parameters)
