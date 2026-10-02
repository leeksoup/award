# ClickBank lifetime-course pilot

## Scope and decisions

Implement one ClickBank one-time, lifetime-course SKU. Affiliate traffic arrives through a ClickBank HopLink, visits our pitch page, and supplies the learner's email before being redirected to the ClickBank order form. The buyer does not need a Drupal account. The learner may be someone other than the buyer. ClickBank handles checkout, receipts, refunds, and its own return policy. Recurring subscriptions, VIP, upsells, and multi-item orders are out of scope.

## Implementation

1. Add disabled-by-default configuration mapping the ClickBank vendor and SKU to one existing lifetime LMS offer. Keep the INS secret and Orders API key in non-exported Drupal settings. Require an Orders API key with Order Read permission.
2. Add a learner-email form that creates a short-lived, server-side checkout intent and redirects to the ClickBank order form with only an opaque `v1` seller variable. Do not expose the learner email in the URL.
3. Receive ClickBank INS version 8 notifications, decrypt and validate the envelope, persist/deduplicate them, acknowledge quickly, and process them in a queue. Verify the receipt, SKU, sale, buyer, and vendor against ClickBank Orders API before granting access. Missing or expired intents require staff review; never silently substitute the buyer email.
4. Extend the entitlement ledger to support a ClickBank source without a Commerce order or PayPal payment. Reuse learner invitations and Group membership ownership. Keep PayPal-only cancellation and the site's PayPal guarantee out of ClickBank entitlements. Use a monotonic update hook after 10019.
5. Treat verified refunds and chargebacks as terminal and revoke only memberships owned by the ClickBank entitlement. A delayed sale must never reactivate a refunded receipt. Add Orders API reconciliation and a receipt-specific retry command for missed notifications.
6. Add a thank-you page that explains invitation timing without directly granting access, plus staff-facing status and exception visibility. Document setup, ClickBank return terms, test cases, and rollout. Keep the feature disabled until the ClickBank account, SKU, API key, secret, pitch link, and staging test purchase are verified.

## Verification

Test an affiliate HopLink purchase and separate buyer/learner emails; duplicate/delayed notifications; forged or malformed INS; API outage; missing or expired intent; existing learner; wrong SKU/vendor; refund/chargeback reversal; and pre-existing Group memberships. Run PHP lint, YAML validation, Composer validation, PHPCS/PHPUnit when available, and staging Drupal update/queue checks. Do not claim a real purchase test without actual ClickBank and bootstrapped-site evidence.

## References

- [ClickBank seller guide](https://support.clickbank.com/en/articles/10535160-how-do-i-get-started-as-a-clickbank-seller)
- [INS version 8](https://support.clickbank.com/en/articles/10535147-instant-notification-service-ins)
- [Orders API](https://support.clickbank.com/en/articles/10535407-orders-api)
- [ClickBank API authentication](https://support.clickbank.com/en/articles/10535397-clickbank-api-specifications)
- [Seller variables](https://support.clickbank.com/en/articles/10535222-query-string-parameters)
- [HopLinks](https://support.clickbank.com/en/articles/10535278-hoplinks-guide)
- [ClickBank returns](https://support.clickbank.com/en/articles/10535349-clickbank-s-return-and-subscription-cancellation-policy)
