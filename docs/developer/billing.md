# Billing & the freemium gate

Madori's paid tier is the **Team** plan: a per-seat subscription **billed to a
Space**, sold through Stripe-hosted Checkout + Customer Portal. The gate is
*hybrid* — a free shared Space is capped on both **members** and **MCP/API
throughput**, and an active subscription lifts both.

> **Launch flag.** Enforcement ships **dark**: `app.billing_enforcement_enabled`
> is `false`, so every cap short-circuits to "allowed". The data model, Stripe
> flow, and webhook sync all run regardless; flip the flag on once Stripe is
> live and prices are set. dev + test keep it off.

## The plan model

| | Free | Team (paid) |
|---|---|---|
| Personal space | unlimited (always) | n/a (never billable) |
| Shared space members | `app.free_space_member_limit` (default 5) | unlimited |
| MCP calls / day / user | `app.free_mcp_daily_limit` (default 100) | unlimited |
| Billable unit | — | the **Space** |

**Entitlement rule:** a user has unlimited MCP/API throughput if they belong
(directly or via a group) to **at least one Space with an entitling
subscription**. "Entitling" = status in
`Subscription::ENTITLING_STATUSES` (`active`, `trialing`, `past_due` — the last
so a transient payment hiccup doesn't instantly lock a team out; only a
terminal `canceled`/`unpaid` revokes).

**Admin exemption:** platform admins (`ROLE_ADMIN`) are never capped on MCP
throughput — operating the instance shouldn't be rate-limited by the freemium
gate. Their usage is *still counted* (so it shows up in usage reports and the
settings panel); only the cap check is bypassed (`UsageLimiter::isAdmin()`,
folded into the same unlimited path as entitlement).

Only programmatic **MCP** traffic is metered — the PWA's own
cookie-authenticated REST calls are never counted. Counting itself stays in the
existing `UsageRecorder`/`UserUsageCounter` (off the request latency path);
`UsageLimiter` only *reads* those counters to decide.

## Moving pieces

| Concern | Where |
|---|---|
| Subscription mirror of Stripe state | `App\Entity\Subscription` (table `subscription`, one per Space) |
| Queries (active-for-space, by-stripe-id, entitlement) | `App\Repository\SubscriptionRepository` |
| Gate read-side (caps, entitlement, remaining) | `App\Service\UsageLimiter` |
| Stripe API (Checkout, Portal, webhook verify) | `App\Billing\StripeGatewayInterface` + `StripeGateway` (HttpClient, no SDK) |
| HTTP surface | `App\Controller\BillingController` |
| MCP cap enforcement | `App\Controller\McpController` (`isMcpCallAllowed` before dispatch) |
| Member cap enforcement | `SpaceMemberController`, `BoardMemberController` (402 at the cap) |

The **Subscription row is written only by the webhook**, off Stripe's
authoritative `customer.subscription.*` events — Checkout never persists one, so
a user bailing on the hosted page leaves no half-built billing state. Checkout
stamps `metadata[space_id]` onto the subscription so every later event resolves
back to our Space.

## Endpoints

| Method · Path | Who | Does |
|---|---|---|
| `POST /spaces/{id}/billing/checkout` | space admin | Create a Checkout Session → `{url}` (body `{interval: month\|year}`) |
| `POST /spaces/{id}/billing/portal` | space admin | Create a Customer Portal session → `{url}` |
| `POST /spaces/{id}/billing/cancel` | space admin | Schedule cancel-at-period-end + record the churn survey (body `{reason, comment?}`) → `{ok, cancelAtPeriodEnd, currentPeriodEnd}` |
| `GET /spaces/{id}/billing` | space member | Plan/status/seats/period + free limits |
| `POST /billing/webhook` | Stripe (signed) | Upsert the Subscription from `customer.subscription.*`; email a receipt on `invoice.payment_succeeded` |

The **cancel** route is owned in-app (rather than punting to the Stripe portal)
so we control the moment the "why are you leaving?" survey is asked. It validates
the required `reason`, calls `cancelSubscriptionAtPeriodEnd()` on the gateway
(Stripe `cancel_at_period_end=true` — access continues until period end, no
further charge), records the survey only after Stripe accepts, then optimistically
flags the mirror row's `cancelAtPeriodEnd` (the webhook confirms it). See the
**Cancellation feedback** section below.

**Account deletion cancels the card immediately.** A personal `Subscription` is
`onDelete: CASCADE` on `ownerUser`, so deleting the account would erase our
mirror while leaving the Stripe subscription live and renewing. `AccountDeletionService`
therefore calls `cancelSubscription()` (Stripe `DELETE /subscriptions/{id}` —
immediate, not period-end) on every still-entitling personal subscription
*before* the delete transaction. It's best-effort and outside the transaction:
a Stripe outage logs a warning but never blocks a GDPR deletion. Org/space
subscriptions are left alone — those accounts outlive the departing member.

The webhook is unauthenticated at the firewall (no Bearer, no cookie) — its
authenticity is the `Stripe-Signature` HMAC, verified in `StripeGateway`
(HMAC-SHA256 over `"{timestamp}.{raw body}"`, constant-time compare, 5-minute
tolerance). All other routes enforce auth in the controller, mirroring the
existence-hiding 404/403 shape of the rest of the Space API.

## Why HttpClient, not `stripe/stripe-php`

Our Stripe surface is two `POST`s plus signature verification. Symfony
HttpClient is already in the stack (pulled in by `symfony/resend-mailer`), so we
avoid adding an SDK + the `composer.lock` churn. Nested request bodies are
flattened to Stripe's bracket notation by HttpClient automatically.

## Configuration

```yaml
# api/config/services.yaml
app.free_mcp_daily_limit: 100
app.free_space_member_limit: 5
# Enforcement is env-driven (default off) — flip it at go-live with the
# BILLING_ENFORCEMENT_ENABLED env, no code deploy.
app.billing_enforcement_enabled: '%env(bool:default:app.billing_enforcement_enabled_default:BILLING_ENFORCEMENT_ENABLED)%'
```

```dotenv
# api/.env (blank = billing disabled; the gateway no-ops and endpoints 503).
# Override in .env.local (dev) or the server env (compose.yaml env blocks) for
# staging/prod — NOT the tracked api/.env.
STRIPE_SECRET_KEY=              # sk_test_... / sk_live_...
STRIPE_WEBHOOK_SECRET=          # whsec_... from the dashboard webhook endpoint
STRIPE_PRICE_PRO_MONTHLY=       # price_... (Pro plan, monthly) — + _YEARLY
STRIPE_PRICE_BUSINESS_MONTHLY=  # price_... (Business per-seat) — + _YEARLY
STRIPE_CONNECT_FEE_BPS=         # platform fee on client-invoice Connect payments, bps (blank/0 = none)
BILLING_ENFORCEMENT_ENABLED=    # blank/false = caps dark; `true` at go-live
```

Connect (client invoice payments) reuses `STRIPE_SECRET_KEY` — no separate key.
See the **Client payments via Stripe Connect** section below for the full model.

## The webhook events

`POST /billing/webhook` dispatches on six event types. Subscribe your endpoint
to all of them:

| Event | Drives |
| --- | --- |
| `customer.subscription.created` / `.updated` / `.deleted` | subscription state sync (`Subscription` rows) |
| `invoice.payment_succeeded` | subscription receipt email (`SubscriptionReceiptMailer`) |
| `checkout.session.completed` | **marks a client invoice paid** after Checkout (`markInvoicePaid`) — required for online invoice pay |
| `account.updated` | **Connect** onboarding readiness sync onto the `Space` |

The last two are easy to forget; without `checkout.session.completed` an online
invoice payment succeeds at Stripe but never flips the invoice to paid.

## Stripe Sandboxes ⚠️ (Connect gotcha)

Stripe's Connect onboarding wizard funnels you into a **separate sandbox account**
(its own `acct_…` + its own API keys), *not* your main account's test mode. Connect
enabled in that sandbox does **not** reach a key issued by the main account, and
the main account only gains Connect once the platform is **activated** (go-live /
"Verify your account"). So there are two clean setups — pick per environment:

### Local — test memberships **and** invoicing/Connect together

Point local at the **sandbox** that has Connect set up (one account covers both
flows). In `api/.env.local` (never the tracked `api/.env`):

1. `STRIPE_SECRET_KEY` = the **sandbox's** test secret key (Developers → API keys
   while inside that sandbox).
2. Create the Pro / Business (/ Team) test **prices in the sandbox**; set the
   `STRIPE_PRICE_*` ids.
3. Forward webhooks to localhost with the Stripe CLI — it prints the signing
   secret to drop into `STRIPE_WEBHOOK_SECRET`:
   ```bash
   stripe listen --forward-to https://localhost/billing/webhook
   ```
4. `APP_FRONTEND_URL=https://localhost` (already the default) so Connect Account
   Link return/refresh URLs point back at the local app.

Then exercise both: subscribe to a plan (memberships), and on a shared space
`/spaces/{id}/settings → Set up payments` → Express onboarding → pay an invoice
with test card `4242 4242 4242 4242`.

### Production — go live

1. **Activate** your Stripe platform (Verify your account → Go live: business /
   KYC + agreements). This also activates **Connect** on the live account, so
   client-invoice payments settle to connected accounts.
2. Create **live** products/prices → copy the live `price_…` ids.
3. Add a **live** webhook → `https://<host>/billing/webhook` with all six events
   above → copy the live `whsec_…`.
4. Set `STRIPE_SECRET_KEY` (`sk_live_…`), `STRIPE_WEBHOOK_SECRET`, and the price
   ids in **`compose.yaml`'s `php` + `worker` `environment:` blocks** — not just
   `/opt/aura/.env` (the recurring gotcha; env there alone doesn't reach the
   containers).
5. Set `BILLING_ENFORCEMENT_ENABLED=true` (same env blocks) if you want the free
   caps to bite. Redeploy.

## Testing

- `App\Tests\Service\UsageLimiterTest` — caps + entitlement against the real DB,
  building the limiter with enforcement on.
- `App\Tests\Api\BillingTest` — endpoint gating + webhook sync via the in-memory
  fake (`App\Tests\Billing\InMemoryStripeGateway`, which reports itself
  configured and accepts a fixed signature).
- `App\Tests\Billing\StripeGatewayTest` — the real HMAC signature verification,
  offline.

## Cancellation feedback (churn survey)

Every cancellation moment asks **"why are you leaving?"** and stores the answer
in `cancellation_feedback` (`App\Entity\CancellationFeedback`). Three contexts:
`account_deactivation`, `account_deletion`, `subscription_cancellation`. A
**required** single-choice `reason` (one of `CancellationFeedback::REASONS`) plus
an **optional** free-text `comment` ride in the body of the action that triggers
them:

| Action | Endpoint | Context |
|---|---|---|
| Deactivate account | `POST /me/deactivate` | `account_deactivation` |
| Delete account | `POST /me/delete` | `account_deletion` |
| Cancel subscription | `POST /spaces/{id}/billing/cancel` | `subscription_cancellation` |

The shared writer is `App\Service\CancellationFeedbackRecorder` —
`reasonError()` validates (controllers return 422 on a missing/invalid reason),
`record()` persists. Both the `user` and `space` FKs are **SET NULL** so the row
outlives the very action that created it: account deletion removes the user and
the feedback stays behind, anonymized. The entity is **server-written only** (not
an API resource). The PWA mirrors the reason list in
`pwa/lib/cancellationReasons.ts`; the survey UI is `CancellationSurvey`
(`pwa/components/feedback/`), mounted in the deactivate/delete dialogs and the
`SpaceBillingCard` cancel dialog.

## Known follow-ups

- **Seat sync** — Checkout sets the initial quantity to the current member
  count; we don't yet push quantity changes back to Stripe as members come and
  go (entitlement lifts caps entirely, so seat count is informational today).
- **Invite acceptance** isn't gated by the member cap — already-issued invites
  are always honored; only *new* adds/invites are blocked at the cap.
- **Individual Pro ($8/mo)** plan is deferred; the Team product covers launch.
- **Group attach** to a Space isn't cap-checked yet.

## Client payments via Stripe Connect (#connect)

The billing above is Madori charging **users**. A separate flow lets users
charge **their own clients** and have the money settle to them, not the
platform — Stripe **Connect** with **Express** connected accounts, one per
`Space` (the billable unit invoices already belong to).

- **Model**: `Space.stripeConnectAccountId` + `stripeConnectChargesEnabled` +
  `stripeConnectDetailsSubmitted` (server-written only; never serialized —
  readiness is exposed through the status endpoint). `Space::canAcceptClient
  Payments()` = has an account AND charges are enabled.
- **Onboarding** (`App\Controller\ConnectController`, space-admin): `POST
  /spaces/{id}/connect/onboard` creates the Express account (once) and returns a
  hosted **Account Link** URL — Stripe collects identity + bank details, we
  never touch them. `GET /spaces/{id}/connect/status` (any member) reports
  `none | incomplete | pending | ready`, refreshing from Stripe on each call.
- **Payment routing**: `StripePaymentGateway::createInvoicePayment` routes the
  public-pay checkout to the space's connected account as a **destination
  charge** (`payment_intent_data.transfer_data.destination`), so funds settle to
  the space owner while the completion webhook still fires on the *platform*
  (`checkout.session.completed` → `markInvoicePaid`, unchanged). An optional
  platform cut rides via `application_fee_amount`, sized by
  `app.stripe_connect_application_fee_bps` (env `STRIPE_CONNECT_FEE_BPS`, basis
  points, default `0` = no fee).
- **Gate**: `PublicInvoiceController::pay` 503s ("this business has not set up
  online payments yet") unless the space is Connect-ready — so a client payment
  can never silently land in the platform account. Sending/emailing an invoice
  and recording payments manually (mark-paid) are unaffected.
- **Webhook**: `account.updated` mirrors `charges_enabled` / `details_submitted`
  onto the space (best-effort; the status endpoint's refresh is the reliable
  path).
- **PWA**: `SpaceConnectCard` on `/spaces/{id}/settings` — a "Payments" card with
  a Not-set-up / Pending / Active badge and a "Set up payments" button that hands
  off to Stripe onboarding (`?connect=return|refresh` banners on the way back).
- **Going live**: enable Connect + Express in the Stripe dashboard; the same
  `STRIPE_SECRET_KEY` is used. Tests swap `App\Tests\Billing\InMemoryStripe
  Gateway`. Tests: `App\Tests\Api\ConnectTest`.
