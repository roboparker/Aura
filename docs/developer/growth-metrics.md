# Growth metrics

The acquisition funnel for the whole instance: **signup → activation → paid → churn**, plus a current-state snapshot and a weekly digest email.

Answers "how is the product doing", as distinct from `docs/developer/business-analytics.md`, which answers "how is a customer's business doing".

- Dashboard: `/admin/growth` (admin only)
- Endpoint: `GET /admin/growth`
- Backend: `api/src/Growth/GrowthMetrics.php`

## Why first-party and not PostHog

The launch plan called for PostHog or Plausible. We built it in-house instead, for three reasons:

1. **The data is already ours.** A signup, a first task, a subscription — these are rows in our database, not clickstream that needs a tracker to observe. Shipping user-level events to a third party to re-derive facts we already hold is work that buys nothing.
2. **It would contradict the privacy posture.** Umami was chosen for pageviews specifically because it's self-hosted and cookieless (`docs/developer/analytics.md`). Sending identified user behaviour elsewhere undoes that.
3. **Retention windows.** The launch plan's 30-day review depends on these numbers. On a free tier they'd sit behind someone else's data-retention limit.

Umami still handles anonymous pageviews and front-end events. The two don't overlap: Umami sees traffic, this sees accounts.

## The funnel

| Stage | Definition | Source |
|---|---|---|
| Signups | Accounts created | `user.created_at` |
| Activations | First task a user ever created, bucketed by *when that happened* | `MIN(task.created_on)` per owner |
| Upgrades | Subscription reaching a paying status | `subscription.created_at` + entitling status |
| Churn | Subscription canceled or unpaid | `subscription.updated_at` + ended status |

Activation is bucketed by the first task rather than by signup date on purpose — otherwise the series is just signups re-cut, and can't show whether onboarding is working.

### `user.created_at` was added late

Nothing recorded when an account was created until `Version20260726060302`, which made the whole funnel unmeasurable. Rather than stamping every existing row with the migration date, it's **backfilled from each user's personal space** — that space is created in the same flush as the account, so historical signup dates are accurate.

## Honest limits

Worth knowing before quoting any of these numbers:

- **MRR is an estimate.** Computed from list prices × seats, from `app.plan_price_*_cents`. It ignores coupons, proration, tax, and failed charges. Annual plans bill at 10× monthly (two months free), so they contribute `monthly × 10 / 12`. **Stripe is the source of truth for revenue** — this is for watching a trend, not for accounting.
- **Churn is approximate.** There's no subscription status history, so a canceled subscription that gets edited later moves buckets. Fine for a trend, not an audit trail.
- **Conversion is null, not zero, when nobody entered the funnel.** "0% converted" from no signups is a claim the data can't support, and on a launch dashboard that difference matters.
- **Waitlist skews everything.** While waitlist mode is on, signups can't convert, so conversion sits near zero by construction. The dashboard says so rather than letting you read it as a product failure.

## Delivery

A dashboard nobody opens doesn't govern anything, so the numbers are pushed too:

- **Weekly digest** — `App\Service\GrowthDigestMailer::sendWeekly()`, Mondays 09:00 UTC via `MainScheduleProvider`. Last week's four stages with a week-on-week delta, plus the snapshot.
- **Paid-signup alert** — fired from the Stripe webhook the moment a subscription first reaches a paying status. It compares the status *before* the write, so Stripe re-delivering an event for an already-paying subscription doesn't re-alert. Best-effort: a failed alert never fails the webhook, because that would make Stripe retry a payment event we already recorded.

Both go to every admin, resolved by a `roles::text` SQL prefilter (JSON column — DQL `LIKE` won't work) confirmed through `User::getRoles()`, so a waitlisted account never receives them.

## Adding a metric

Add a private method to `GrowthMetrics` returning `list<array{period, value}>` and include it in `funnel()`. Bucketing helpers are already there; `$interval` maps through a fixed lookup before reaching `date_trunc`, so it is never interpolated from request input.

Tests: `App\Tests\Api\GrowthMetricsTest`, `App\Tests\Api\GrowthDigestTest`, `pwa/lib/growthTypes.test.ts`.
