# Commerce LMS Entitlements production setup

This checklist covers the Drupal-side work required after deploying the
Commerce LMS Entitlements code and importing the site's exported
configuration. It distinguishes configuration transferred by `cex`/`cim`
from content entities and external PayPal resources that must be verified or
created separately.

## What configuration import does and does not transfer

| Item | Moved by `cex`/`cim`? | Production action |
|---|---:|---|
| Checkout flows and panes | Yes | Verify the imported configuration. |
| LMS offers | Yes | Verify that referenced variations and Groups exist. |
| Subscription campaigns | Yes | Run the entitlement audit. |
| Role permissions | Yes | Verify that authenticated users can book VIP sessions. |
| Commerce products and variations | No | Verify or create them. |
| Commerce promotions and coupons | No | Recreate them. |
| LMS Course and Class Groups | No | Verify the existing entity IDs. |
| Recurring Events series and instances | No | Create or verify them. |
| PayPal products and plans | External | Verify their Drupal mappings. |
| PayPal live webhook | External | Verify its URL and webhook ID. |
| Existing orders and entitlements | No | Do not copy them from staging. |

## 1. Import configuration

On production, run:

```bash
drush updb -y
drush cim -y
drush cr
drush updatedb:status
```

Keep the Commerce promotions disabled or absent while completing the rest of
the setup.

## 2. Verify Commerce products and variations

LMS offers reference Commerce product variation IDs. Confirm that every
referenced variation exists in production:

```bash
drush php:eval '
$variation_storage = \Drupal::entityTypeManager()
  ->getStorage("commerce_product_variation");

foreach (\Drupal::entityTypeManager()
  ->getStorage("commerce_lms_offer")
  ->loadMultiple() as $offer) {
  $variation_id = $offer->getVariationId();
  $variation = $variation_storage->load($variation_id);

  printf(
    "%s: variation %d — %s — %s\n",
    $offer->id(),
    $variation_id,
    $variation ? $variation->label() : "MISSING",
    $variation && $variation->getPrice()
      ? (string) $variation->getPrice()
      : "no price"
  );
}
'
```

The monthly and annual variations should have base prices of `$19` and `$197`,
respectively.

If a variation is missing, create or import the Commerce product and
variation first. Then edit the LMS offer so that it references the production
variation ID.

## 3. Verify Course and Class mappings

```bash
drush php:eval '
$groups = \Drupal::entityTypeManager()->getStorage("group");

foreach (\Drupal::entityTypeManager()
  ->getStorage("commerce_lms_offer")
  ->loadMultiple() as $offer) {
  foreach ($offer->getCourseClassMap() as $mapping) {
    $course = $groups->load($mapping["course_id"]);
    $class = $groups->load($mapping["class_id"]);

    printf(
      "%s: Course %d=%s; Class %d=%s\n",
      $offer->id(),
      $mapping["course_id"],
      $course ? $course->label() : "MISSING",
      $mapping["class_id"],
      $class ? $class->label() : "MISSING"
    );
  }
}
'
```

Production must contain these Groups with these IDs. Each configured Class
must also be related to its corresponding Course.

## 4. Configure the VIP hub and sessions

Create and administer Recurring Events series at:

```text
/admin/content/events/series
```

The add-series page is:

```text
/events/add
```

If an appropriate event-series type does not exist, administer its type at:

```text
/admin/structure/events/series/types/eventseries_type
```

Create at least one series with future instances. Enable instance
registration, set its capacity, and disable its waitlist. After saving it,
record the numeric series ID from its `/events/series/ID` URL.

Open:

```text
/admin/commerce/config/lms-vip
```

Verify all of the following:

- The VIP Course exists.
- The VIP Class exists and is a child of that Course.
- At least one eligible Recurring Events series exists.
- The series supports instance registration.
- Capacity is configured.
- Waitlists are disabled if that is the intended policy.
- Future event instances have been generated.
- The meeting URL is the real protected production URL.
- The booking cutoff is correct.

Event series and instances are content entities and do not arrive through a
configuration import. If IDs exported from staging do not exist in production,
create the production series and update the VIP settings with the production
IDs.

## 5. Verify the VIP permission

```bash
drush config:get user.role.authenticated permissions \
  | rg -F 'book own vip sessions'
```

Alternatively, use an explicit boolean check:

```bash
drush php:eval '
$role = \Drupal\user\Entity\Role::load("authenticated");
print "book own vip sessions: "
  . ($role && $role->hasPermission("book own vip sessions") ? "yes" : "no")
  . PHP_EOL;
'
```

If the permission is missing:

```bash
drush role:perm:add authenticated 'book own vip sessions'
drush cr
```

## 6. Recreate Commerce promotions and coupons

Open:

```text
/admin/commerce/promotions
```

Create the promotions only after importing the subscription campaign
configuration. For each promotion:

- Choose the **LMS subscription campaign** offer plugin.
- Select the matching campaign.
- Reproduce its availability dates.
- Reproduce its overall promotion usage limit.
- Add the intended coupon code.
- Reproduce the coupon usage limit.
- Leave per-customer limits unlimited when guest checkout must work.
- Initially leave the promotion disabled.

The expected promotions include:

- The launch/free-VIP campaign promotion.
- The 50%-off introductory campaign promotion.

Commerce promotions and coupons are content entities and are not exported by
`drush cex`.

## 7. Switch the offers to live

Open both offer forms:

```text
/admin/commerce/config/lms-offers/monthly
/admin/commerce/config/lms-offers/annual
```

For each offer:

- Confirm the live subscription gateway.
- Confirm the ordinary live base plan.
- Confirm the ordinary live VIP-inclusive plan.
- Change the active checkout environment to **Live**.
- Save the offer.

Because changing an offer changes configuration, run `drush cex -y` afterward
and return the updated offer YAML to the authoritative configuration source.
Otherwise, a later configuration import could switch production back to the
sandbox environment.

## 8. Verify the live PayPal gateway and webhook

In the production payment gateway configuration, verify:

- The gateway uses live mode.
- The live client ID and secret are correct.
- The webhook ID belongs to the live PayPal application.
- PayPal's webhook URL points to:

```text
https://PRODUCTION-DOMAIN/commerce-lms-entitlements/paypal/webhook/GATEWAY_ID
```

Do not use sandbox credentials or a sandbox webhook ID in production.

## 9. Run the final audit

```bash
drush cr
drush commerce-lms-entitlements:audit
```

The required results include:

```text
Failed webhook events: 0
Remote PayPal plan mismatches/errors: 0
Unconfigured live PayPal mappings: 0
Invalid live PayPal mappings: 0
Invalid subscription campaigns: 0
```

Resolve any audit error before opening checkout to buyers.

## 10. Perform a controlled live test

Enable only the coupon being tested and complete one real transaction. Verify:

- The coupon applies.
- Drupal shows the correct subtotal and VIP treatment.
- PayPal displays the correct introductory and renewal schedule.
- The PayPal subscription becomes active.
- Webhook events are processed successfully.
- The entitlement becomes active.
- Course and Class memberships are granted.
- `/vip-sessions` is accessible to the learner.
- The learner can book a session.

After the controlled transaction succeeds, enable the public promotion.

## Database warning

Do not replace the production database merely to transfer this setup. A
staging database can contain sandbox orders, test entitlements, webhook
records, users, coupons, and other test state, and loading it would overwrite
production data. Use configuration import for configuration entities, and
create or migrate the required content entities deliberately.
