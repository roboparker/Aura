# Sentry (error tracking + performance tracing)

Madori reports errors and a sampled slice of performance traces to
[Sentry](https://sentry.io) from three surfaces:

| Surface | SDK | Where it initialises |
| --- | --- | --- |
| API (Symfony, `php` container) | [`sentry/sentry-symfony`](https://docs.sentry.io/platforms/php/guides/symfony/) | `api/config/packages/sentry.yaml` |
| Worker (Messenger, same image) | same bundle | same config; capture via the `messenger` integration |
| PWA (Next.js) | [`@sentry/nextjs`](https://docs.sentry.io/platforms/javascript/guides/nextjs/) | `pwa/instrumentation-client.ts` · `pwa/instrumentation.ts` · `pwa/sentry.{server,edge}.config.ts` |

**The whole thing is off until a DSN is set.** A blank DSN makes each SDK a
no-op, so a fresh checkout, the PHPUnit/Vitest suites, and CI never phone home.
Turning Sentry on is purely a matter of setting the DSN env vars in the
deployment — no code change.

## Sentry projects & DSNs

Create **two projects** in the Sentry org:

1. A **PHP** project — its DSN is shared by the API **and** the worker (they run
   the same image; Sentry distinguishes them by `server_name`/transaction data).
   This DSN is a **server secret** — set it via env, never commit it.
2. A **Next.js** project — its DSN is **public** (it ships in the browser
   bundle, exactly like the Umami website ID), so it can be committed in
   `pwa/.env.production` once created.

## Backend configuration

Config lives in [`api/config/packages/sentry.yaml`](../../api/config/packages/sentry.yaml).
Env vars (defaults committed blank/`0.1` in `api/.env`):

| Var | Purpose |
| --- | --- |
| `SENTRY_DSN` | PHP project DSN. Blank = disabled. |
| `SENTRY_TRACES_SAMPLE_RATE` | Tracing sample fraction `0..1` (default `0.1`). `0` = errors only. |
| `SENTRY_RELEASE` | Optional release marker (e.g. deploy image tag). |

What's captured automatically:

- Unhandled exceptions and HTTP 5xx (the bundle's error listener).
- Failed Messenger jobs — the async queue, task reminders, notification
  digests, calendar sync, and backups (`messenger.enabled: true`,
  `capture_soft_fails: true`).
- Performance transactions at `SENTRY_TRACES_SAMPLE_RATE`.

`send_default_pii` is **false** — no usernames, emails, IPs, cookies, or request
bodies are attached, matching the app's privacy-first posture.

> **Note — logged errors vs exceptions.** We intentionally do **not** register a
> broad Monolog handler that turns every `logger->error()` into a separate Sentry
> issue: Symfony already logs uncaught exceptions through Monolog, so such a
> handler would double-report what the error listener already captures. If you
> later want standalone log-as-issue capture, add `Sentry\Monolog\Handler`
> scoped to the app channels (excluding `request`/`console`) in `when@prod`.

## Frontend configuration

`next.config.js` is wrapped with `withSentryConfig`. The SDK is gated on
`NEXT_PUBLIC_SENTRY_DSN`, so the wrapper is inert until a DSN is set.

| Var | Where | Purpose |
| --- | --- | --- |
| `NEXT_PUBLIC_SENTRY_DSN` | `pwa/.env.production` (public) | Next.js project DSN. Blank = disabled. |
| `NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE` | `pwa/.env.production` | Tracing fraction `0..1` (default `0.1`). |
| `NEXT_PUBLIC_SENTRY_ENVIRONMENT` | `pwa/.env.production` | Environment tag (default `production`). |

These `NEXT_PUBLIC_*` vars are **baked in at build time**, so a change requires a
rebuild of the PWA image.

### Source maps (optional)

Readable stack traces need source maps uploaded at build time. Upload only runs
when `SENTRY_AUTH_TOKEN` is present, so the default build (no token) succeeds
without it. To enable, pass these at PWA build time (CI build args / env):

- `SENTRY_ORG` — your Sentry org slug
- `SENTRY_PROJECT` — the Next.js project slug
- `SENTRY_AUTH_TOKEN` — a Sentry auth token with `project:releases` scope (**secret**)

## Going live checklist

1. Create the two Sentry projects; copy their DSNs.
2. **API + worker**: set `SENTRY_DSN` (and optionally `SENTRY_RELEASE`) in the
   server's untracked `.env` (Compose) or the `sentry.dsn` Helm value
   (lands in the chart Secret). `SENTRY_TRACES_SAMPLE_RATE` is already `0.1`.
3. **PWA**: paste the Next.js DSN into `NEXT_PUBLIC_SENTRY_DSN` in
   `pwa/.env.production`, commit, and rebuild the PWA image.
4. (Optional) Wire `SENTRY_ORG` / `SENTRY_PROJECT` / `SENTRY_AUTH_TOKEN` into the
   PWA build for source-map upload.
5. Deploy, then verify. Sign in as an admin and open **`/admin/sentry-test`**
   (sidebar → **Admin → Sentry test**). It has one button per surface:
   throw a client error (Next.js project), trigger an API 500, and queue a
   worker job that fails on purpose (both → the PHP project). Confirm each lands
   in Sentry. The triggers are non-destructive; the worker job dead-letters into
   the `failed` transport (`bin/console messenger:failed:show` to inspect/clear).

The endpoints behind that page — `GET /admin/sentry-test/error` and
`POST /admin/sentry-test/worker` (`App\Controller\SentryTestController`) — are
gated to `ROLE_ADMIN`.

## Tuning

- **Quota tight?** Lower `SENTRY_TRACES_SAMPLE_RATE` /
  `NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE` toward `0` (errors are still captured).
- **Ad blockers dropping events?** Enable a same-origin tunnel by setting
  `tunnelRoute` in `withSentryConfig` (see `pwa/next.config.js`).
