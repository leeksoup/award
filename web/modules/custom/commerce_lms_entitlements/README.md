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
it does not replace the contributed checkout or subscription SDK.

For a full code and operational guide, read
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). It documents every source file,
table, lifecycle state, route, queue, and PayPal/Group integration boundary.

## Offer model

Create three Commerce variations: monthly, annual, and lifetime. Create an LMS
offer for each variation at `/admin/commerce/config/lms-offers`.

- Monthly and annual offers use the contributed PayPal subscription gateway and
  a pre-created PayPal plan ID.
- Lifetime uses normal Commerce PayPal Checkout and has no plan ID.
- Each offer names its only permitted payment gateway and contains an ordered
  `COURSE_ID:CLASS_ID` target list. Administrators select the Classes; buyers
  only select the learner.

The module validates that every selected Class is an existing `lms_class` child
of its configured `lms_course`. It does not require a Course to have only one
Class.

## Access lifecycle

The learner checkout pane stores an existing user or a 30-day invitation on the
order. The contributed subscription module dispatches a plan-selection event;
this module validates the offer, creates a pending entitlement, and supplies
the configured plan ID. After approval, the contributed module stores the
PayPal subscription ID on the order.

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

## Staging checks

Test monthly/annual plan selection, lifetime PayPal payment, invitation claim,
multi-Class Course target validation, valid/invalid and duplicate webhooks,
cancellation timing, failed payment/recovery, and Group `view`/`take` access.
This repository has no bootstrapped Commerce site, so those checks are not yet
run here.
