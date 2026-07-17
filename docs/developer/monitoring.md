# Error & performance monitoring (Sentry)

Aura reports errors and light performance traces to [Sentry](https://sentry.io)
across all three runtimes — the **API**, the **worker**, and the **PWA**. It is
**dark-launched**: with no DSN configured the SDKs stay disabled, so local dev,
the test suite, and CI never send anything. You turn it on by setting the DSN in
the environment (exactly like the VAPID keys and the calendar-webhook URL).

## What's captured

- **Errors / unhandled exceptions** everywhere (API HTTP requests, worker
  cron + messenger failures, PWA client + server).
- **Performance traces** at a low sample rate (`0.1` = 10% by default) so the
  free-tier quota lasts. Tune with `SENTRY_TRACES_SAMPLE_RATE` (API) /
  `NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE` (PWA). `0` disables tracing.
- **No PII** by default (`send_default_pii: false`) — no IPs, cookies, or
  request bodies.
- **Session Replay is off** (both PWA sample rates `0`) to conserve quota and
  keep the client bundle lean.
- **Structured Logs** — application logs are mirrored into Sentry's Logs product
  *in addition to* their normal destination (so nothing changes about existing
  logging). On the API/worker, `enable_logs: true` plus the Monolog
  `Sentry\SentryBundle\Monolog\LogsHandler` (wired as an extra `sentry_logs`
  handler in `monolog.yaml` `when@prod`, `info`+, noisy `event`/`doctrine`
  channels excluded) forward records — Monolog already fans each record out to
  every handler, so it keeps hitting stderr too. Records whose context carries an
  exception are skipped (they surface as issues instead). On the PWA, each
  `Sentry.init` sets `enableLogs: true` + `consoleLoggingIntegration` so
  `console.*` (log/info/warn/error) is mirrored while still printing normally.
  Logs count against a separate free-tier quota from errors/traces.
- **Metrics** — the PWA's product-event catalog is mirrored into Sentry Metrics:
  `trackEvent()` (`pwa/lib/analytics.ts`) emits one `Sentry.metrics.count(name, 1)`
  per event alongside the Umami call, so the same coarse events (signup,
  `task-create`, …) show up in Sentry dashboards/alerts. Application metrics are
  included on all Sentry plans (free tier too); the call no-ops without a DSN.
  Add more with `Sentry.metrics.count/gauge/distribution` (JS) or
  `\Sentry\metrics()` (PHP) — there's no config flag, the SDK version is enough.
- **Releases** — every event/log/trace is tagged with the deployed release (the
  image tag = commit SHA). `scripts/deploy.sh` exports `SENTRY_RELEASE=$IMAGES_TAG`
  so the `${SENTRY_RELEASE:-}` refs in `compose.yaml` pick it up; Sentry then
  groups issues by release and flags regressions. Releases are free. (The PWA's
  `NEXT_PUBLIC_SENTRY_RELEASE` is baked at build and is wired when its DSN lands.)
- **Profiling is intentionally NOT enabled.** The free Developer plan has no
  profile-hours quota (profiling is pay-as-you-go only), and the PHP profiler
  additionally needs the `excimer` extension (not in our image). Revisit if we
  ever move off the free tier.

## API + worker (Symfony)

`sentry/sentry-symfony` is registered in `config/bundles.php` and configured in
`config/packages/sentry.yaml`. It reads:

| Env var | Purpose |
| --- | --- |
| `SENTRY_DSN` | Project DSN. **Blank = disabled.** |
| `SENTRY_ENVIRONMENT` | e.g. `production` (SDK reads it directly). |
| `SENTRY_RELEASE` | Release identifier (e.g. the deploy SHA). |
| `SENTRY_TRACES_SAMPLE_RATE` | Trace sample rate (default `0.1`). |

`when@test` forces `dsn: null` so the suite never emits, even if a DSN leaks
into the environment. The vars are wired to the `php` and `worker` services in
`compose.yaml` via `${SENTRY_*}`; the real values live in the server's untracked
`/opt/aura/.env` (same pattern as `VAPID_*` / `CALENDAR_WEBHOOK_BASE_URL`).

## PWA (Next.js)

`@sentry/nextjs` with three init files, all guarded on the DSN:

- `instrumentation-client.ts` — browser (Replay off).
- `sentry.server.config.ts` — Node SSR runtime.
- `sentry.edge.config.ts` — edge runtime.
- `instrumentation.ts` — the Next.js entrypoint that loads the server/edge init.

`next.config.js` is wrapped with `withSentryConfig`. **Source-map upload is
opt-in**: it only runs when `SENTRY_AUTH_TOKEN` (+ `SENTRY_ORG` / `SENTRY_PROJECT`)
are set at build time, so a normal build without them just skips the upload —
no build-time Sentry account needed for the dark launch.

The DSN is **public** (baked into the client bundle at build), so a single
`NEXT_PUBLIC_SENTRY_DSN` serves client + server. **It is NOT committed** — this
is a public repo, and a committed DSN would be inherited by forks and invites
quota abuse. Instead it's injected at CI build time from a **repo-level Actions
secret** (`NEXT_PUBLIC_SENTRY_DSN`) via a Docker `build-arg` (see the
`Build & push pwa image` step in `deploy.yml` + the `ARG`/`ENV` in `pwa/Dockerfile`).
Forks and local builds get no secret → blank DSN → Sentry off.

| Env var | Purpose |
| --- | --- |
| `NEXT_PUBLIC_SENTRY_DSN` | PWA project DSN. **Blank = disabled.** Injected at build from the repo Actions secret (not committed). |
| `NEXT_PUBLIC_SENTRY_ENVIRONMENT` | e.g. `production` (committed in `pwa/.env.production`). |
| `NEXT_PUBLIC_SENTRY_RELEASE` | Commit SHA, passed as a build-arg from `${{ github.sha }}`. |
| `NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE` | Trace sample rate (default `0.1`). |
| `SENTRY_AUTH_TOKEN` / `SENTRY_ORG` / `SENTRY_PROJECT` | Build-time source-map upload (optional). |

> **Even so, the DSN is visible in the deployed browser bundle** (unavoidable for
> a browser SDK). Keeping it out of the repo only stops fork-inheritance and
> casual grep. The real abuse mitigation is Sentry-side: in the PWA project's
> settings, restrict **Allowed Domains / Inbound Filters** to `madori.app` and set
> a **per-key rate limit / spike protection**.

## Going live

1. Create two projects on sentry.io (free tier): one for the API/worker
   (platform: PHP), one for the PWA (platform: Next.js).
2. **API/worker:** add `SENTRY_DSN=<api-dsn>` (+ `SENTRY_ENVIRONMENT=production`,
   optionally `SENTRY_RELEASE=<sha>`) to the server's `/opt/aura/.env`. The
   `compose.yaml` refs are already in place; a deploy recreates the containers
   with the value.
3. **PWA:** add the Next.js project DSN as a **repo-level Actions secret** named
   `NEXT_PUBLIC_SENTRY_DSN` (repo → Settings → Secrets and variables → Actions).
   The deploy build bakes it into the image; nothing to commit. Then, in the PWA
   Sentry project, restrict Allowed Domains to `madori.app` + set a key rate
   limit (the DSN is visible in the shipped bundle).
4. *(Optional)* For readable stack traces, add `SENTRY_AUTH_TOKEN` / `SENTRY_ORG`
   / `SENTRY_PROJECT` to the CI build env so `withSentryConfig` uploads source
   maps.

Until those are set, everything stays inert.

## Verifying the integration

Once the DSNs are set, confirm each surface actually reports. Sign in as an admin
and open **`/admin/sentry-test`** (sidebar → **Admin → Sentry test**). It has one
button per surface:

- **Throw a client error** — an uncaught browser error → the **Next.js** project.
- **Trigger an API error** — calls an endpoint that raises an exception (HTTP 500)
  → the **PHP** project.
- **Trigger a worker error** — queues a job that fails on purpose → the **PHP**
  project once the worker runs it.

The triggers are non-destructive (they touch no data). The worker job throws an
`UnrecoverableMessageHandlingException`, so it fails once (no retry storm) and
dead-letters into the `failed` transport — inspect/clear it with
`bin/console messenger:failed:show` / `:remove`.

The backing endpoints — `GET /admin/sentry-test/error` and
`POST /admin/sentry-test/worker` (`App\Controller\SentryTestController`, plus the
`App\Message\SentryTestFailure` job) — are gated to `ROLE_ADMIN`.
