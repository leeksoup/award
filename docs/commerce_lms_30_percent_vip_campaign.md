# Create a 30%-off campaign with VIP included

This runbook creates a second coupon-backed subscription campaign with a
larger redemption capacity than the initial launch offer. It discounts the
normal base subscription by 30% during the introductory period and includes
VIP at no additional introductory cost.

The campaign uses the existing forced-VIP behavior. At renewal, the
subscription changes to the normal base-plus-VIP price.

## Pricing schedule

| Offer | Introductory period | Introductory price | Renewal price |
|---|---:|---:|---:|
| Monthly | 12 payments | `$13.30/month` | `$31/month` with VIP |
| Annual | 1 payment | `$137.90` | `$324/year` with VIP |

The calculations are:

```text
$19.00 × 70% = $13.30
$197.00 × 70% = $137.90
```

The customer capacity is not stored on the LMS campaign or PayPal plan. It is
configured later as the Drupal Commerce promotion and coupon usage limit.

## Prerequisite

Deploy the campaign-draft workflow from commit `e404d49`, then rebuild caches:

```bash
drush cr
```

That change allows a disabled campaign draft to be saved before its PayPal
plan IDs exist. No database update is required.

## 1. Create a disabled campaign draft

Open:

```text
/admin/commerce/config/lms-subscription-campaigns/add
```

Enter:

- **Name:** `Launch 30% Off with VIP`
- **Machine name:** `launch_30_vip`
- **Enabled:** unchecked
- **Campaign behavior:** **Launch campaign: include VIP at no added introductory cost**
- **Customer-facing terms:** explain the introductory price, number of
  introductory payments, VIP inclusion, and normal renewal price

Configure the annual offer:

- Check **Include this offer in the campaign**.
- Set **Introductory payments** to `1`.
- Set **VIP introductory price — Amount** to `137.90`.
- Set **VIP introductory price — Currency** to `USD`.
- Leave all sandbox and live campaign plan IDs blank.

Configure the monthly offer:

- Check **Include this offer in the campaign**.
- Set **Introductory payments** to `12`.
- Set **VIP introductory price — Amount** to `13.30`.
- Set **VIP introductory price — Currency** to `USD`.
- Leave all sandbox and live campaign plan IDs blank.

The base introductory fields are unused by a forced-VIP campaign and can
remain blank. Save the disabled campaign.

## 2. Generate the sandbox PayPal plans

Preview without changing PayPal or Drupal:

```bash
drush commerce-lms-entitlements:create-campaign-plans launch_30_vip
```

Confirm that the preview reports approximately:

```text
annual    VIP    137.90 USD    324 USD    1
monthly   VIP    13.30 USD     31 USD     12
```

Create, validate, and save the sandbox plan IDs:

```bash
drush commerce-lms-entitlements:create-campaign-plans \
  launch_30_vip \
  --apply
```

## 3. Generate the live PayPal plans

Preview the live plans first:

```bash
drush commerce-lms-entitlements:create-campaign-plans \
  launch_30_vip \
  --environment=live
```

Confirm the introductory and renewal schedules, then create the plans:

```bash
drush commerce-lms-entitlements:create-campaign-plans \
  launch_30_vip \
  --environment=live \
  --apply \
  --confirm-live=CREATE-LIVE-PAYPAL-PLANS
```

The command validates every returned plan and stores its ID on the campaign.
It uses deterministic PayPal request IDs and recovery state to avoid creating
duplicates after a partially successful run.

## 4. Enable and validate the campaign

Return to:

```text
/admin/commerce/config/lms-subscription-campaigns
```

Edit `launch_30_vip`, check **Enabled**, and save. Enabling the campaign
requires valid plans for the currently active checkout environment and
performs remote validation of its configured plans.

Run the complete audit:

```bash
drush commerce-lms-entitlements:audit
```

Required campaign results include:

```text
Subscription campaign valid: launch_30_vip
Invalid subscription campaigns: 0
```

Export the completed campaign configuration:

```bash
drush cex -y
```

When preparing the campaign on staging, deploy and import the resulting
campaign YAML on production before creating the production promotion.

## 5. Create the Commerce promotion and coupon

On production, open:

```text
/admin/commerce/promotions
```

Create a new promotion with:

- **Name:** `Launch 30% Off with VIP`
- **Offer:** **LMS subscription campaign**
- **Campaign:** `Launch 30% Off with VIP`
- The intended sale start and end dates
- The desired larger overall usage limit, such as `50`
- An unlimited per-customer limit when guest checkout must work
- Initially disabled status

Add a coupon to the promotion:

- Use a different code from the 50%-off launch coupon.
- Set the coupon's overall usage limit to the desired capacity.
- Leave its per-customer limit unlimited when guest checkout must work.

For example, setting both overall limits to `50` limits the offer to fifty
successful redemptions. Promotion and coupon limits are Commerce content
entities and are not exported by `drush cex`.

## 6. Perform a controlled test

Before enabling the promotion publicly, perform one controlled checkout and
verify:

- The coupon applies to both eligible recurring offers.
- Monthly checkout shows `$13.30` for 12 introductory payments.
- Annual checkout shows `$137.90` for one introductory payment.
- VIP is forced on without an additional introductory surcharge.
- PayPal shows renewal at `$31/month` or `$324/year`.
- The PayPal subscription becomes active.
- Webhook processing activates the entitlement and LMS memberships.
- VIP session access is granted.

Enable the promotion publicly only after the controlled test succeeds.
