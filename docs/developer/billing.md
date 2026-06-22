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
| Member cap enforcement | `SpaceMemberController`, `ProjectMemberController` (402 at the cap) |

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
| `GET /spaces/{id}/billing` | space member | Plan/status/seats/period + free limits |
| `POST /billing/webhook` | Stripe (signed) | Upsert the Subscription from `customer.subscription.*` |

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
app.billing_enforcement_enabled: false   # flip on once Stripe is live
```

```dotenv
# api/.env (blank = billing disabled; the gateway no-ops and endpoints 503)
STRIPE_SECRET_KEY=          # sk_test_... / sk_live_...
STRIPE_WEBHOOK_SECRET=      # whsec_... from the dashboard webhook endpoint
STRIPE_PRICE_TEAM_MONTHLY=  # price_... (Team product, monthly)
STRIPE_PRICE_TEAM_YEARLY=   # price_... (Team product, yearly)
```

## Going live (one-time Stripe setup)

1. Create a Stripe account; in **test mode** first.
2. Create a **Product "Team"** with a monthly recurring **Price** (and an
   optional yearly one). Copy the `price_...` ids.
3. Add a **webhook endpoint** → `https://<host>/billing/webhook`, subscribed to
   `customer.subscription.created`, `customer.subscription.updated`,
   `customer.subscription.deleted`. Copy the signing secret (`whsec_...`).
4. Set the four `STRIPE_*` env vars on the server (and the price ids).
5. Flip `app.billing_enforcement_enabled` to `true` and deploy.
6. Smoke-test with a Stripe test card, then switch the keys to live mode.

## Testing

- `App\Tests\Service\UsageLimiterTest` — caps + entitlement against the real DB,
  building the limiter with enforcement on.
- `App\Tests\Api\BillingTest` — endpoint gating + webhook sync via the in-memory
  fake (`App\Tests\Billing\InMemoryStripeGateway`, which reports itself
  configured and accepts a fixed signature).
- `App\Tests\Billing\StripeGatewayTest` — the real HMAC signature verification,
  offline.

## Known follow-ups

- **Seat sync** — Checkout sets the initial quantity to the current member
  count; we don't yet push quantity changes back to Stripe as members come and
  go (entitlement lifts caps entirely, so seat count is informational today).
- **Invite acceptance** isn't gated by the member cap — already-issued invites
  are always honored; only *new* adds/invites are blocked at the cap.
- **Individual Pro ($8/mo)** plan is deferred; the Team product covers launch.
- **Group attach** to a Space isn't cap-checked yet.
