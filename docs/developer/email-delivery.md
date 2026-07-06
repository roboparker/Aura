# Email delivery (production)

Madori sends transactional email for signup verification, password reset,
space/group invites, email-change confirmation, waitlist access, and
notification digests/reminders. All of it flows through **Symfony Mailer** and
is dispatched **asynchronously** on the worker (the `SendEmailMessage` is routed
to the `async` transport — see [`job-queue.md`](job-queue.md)), so a running
worker is required for mail to actually leave the queue.

## The transport is env-driven

`config/packages/mailer.yaml` reads a single variable:

```yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
```

| `MAILER_DSN` value | Effect |
| --- | --- |
| _unset_ / `null://null` | **Mail is silently discarded.** This is the default in `api/.env` so a fresh checkout and the test suite never send. |
| `smtp://mailpit:1025` | Local dev — caught by the Mailpit container (compose default). |
| `resend+api://KEY@default` | **Production** — delivered via Resend. |

> Leaving `MAILER_DSN` empty in production means password resets and invites go
> nowhere. Setting a real transport is a launch prerequisite.

## Recommended provider: Resend

The `symfony/resend-mailer` bridge is already installed, so no code or
dependency change is needed — production is purely configuration.

1. Create a [Resend](https://resend.com) account and **verify your sending
   domain** (add the SPF + DKIM DNS records Resend gives you). Deliverability
   depends on this — unverified domains land in spam or are rejected.
2. Create an **API key** (Sending access).
3. Set two variables in the server environment:

   ```dotenv
   MAILER_DSN=resend+api://re_your_api_key@default
   MAILER_FROM=no-reply@yourdomain.com   # must be on the verified domain
   ```

   `APP_FRONTEND_URL` must also point at the public PWA origin (e.g.
   `https://app.yourdomain.com`) so links in the emails resolve correctly.

`MAILER_FROM` is the default `From:` for every mailer (each service autowires
`%env(default::MAILER_FROM)%` and falls back to a dev address when unset).

## Where the env goes

### Docker Compose (current production)

`compose.yaml` already threads all three values through to both the `php` and
`worker` services:

```yaml
MAILER_DSN: ${MAILER_DSN:-smtp://mailpit:1025}
MAILER_FROM: ${MAILER_FROM:-no-reply@madori.test}
APP_FRONTEND_URL: ${APP_FRONTEND_URL:-https://localhost}
```

Set the real values in the untracked `/opt/aura/.env` on the server (same
pattern as `VAPID_*` and `STRIPE_*`). The deploy does `git reset --hard`, so
this file must **not** be tracked in git.

### Helm

The chart injects the same variables into the web and worker Deployments:

- `mailer.dsn` → the `mailer-dsn` **Secret** key (holds the API key).
- `mailer.from` → the `mailer-from` ConfigMap key.
- `app.frontendUrl` → the `app-frontend-url` ConfigMap key.

```yaml
# values.yaml (or a values override / secret manager)
mailer:
  dsn: "resend+api://re_your_api_key@default"
  from: "no-reply@yourdomain.com"
app:
  frontendUrl: "https://app.yourdomain.com"
```

An empty `mailer.dsn` defaults to `null://null` so a chart install without mail
configured still boots.

## Verifying

After setting the DSN, trigger a password reset from the sign-in page (or run
`bin/console app:notifications:dispatch-digest` for a digest) and confirm the
message arrives. In Resend, the **Logs** tab shows every send with its status.

## Related

- [`password-reset.md`](password-reset.md) — the reset token model + `MAILER_FROM` note.
- [`job-queue.md`](job-queue.md) — why the worker must be running for mail to send.
- [`deployment.md`](deployment.md) — server env + secrets layout.
