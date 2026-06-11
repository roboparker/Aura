# Deployment Guide

## Local Development (Docker Compose)

### Start
```bash
docker compose up -d
```

### Access
- **API / PWA**: https://localhost (accept self-signed cert)
- **API docs**: https://localhost/docs
- **Admin panel**: https://localhost/admin

### Stop
```bash
docker compose down
```

### Environment Variables

Key variables (set in `.env` or `compose.override.yaml`):

| Variable                       | Default                  | Description              |
|--------------------------------|--------------------------|--------------------------|
| `COMPOSE_PROJECT_NAME`         | (directory name)         | Isolates containers/volumes per stack |
| `SERVER_NAME`                  | `localhost`              | Server hostname          |
| `POSTGRES_USER`                | `app`                    | Database user            |
| `POSTGRES_PASSWORD`            | `!ChangeMe!`             | Database password        |
| `POSTGRES_DB`                  | `app`                    | Database name            |
| `POSTGRES_PORT`                | `5432`                   | Host port for PostgreSQL (dev override) |
| `CADDY_MERCURE_JWT_SECRET`     | `!ChangeThisMercure...`  | Mercure JWT secret       |
| `HTTPS_PORT`                   | `443`                    | HTTPS port               |
| `HTTP_PORT`                    | `80`                     | HTTP port                |
| `HTTP3_PORT`                   | `443`                    | HTTP/3 (UDP) port        |
| `MAILPIT_SMTP_PORT`            | `1025`                   | Mailpit SMTP port        |
| `MAILPIT_WEB_PORT`             | `8025`                   | Mailpit web UI port      |
| `UMAMI_SERVER_NAME`            | `analytics.localhost`    | Hostname for the Umami dashboard site block (prod: `analytics.<domain>` + DNS A record) |
| `UMAMI_PORT`                   | `3001`                   | Loopback-bound host port for the Umami dashboard (SSH-tunnel fallback) |

### Parallel Worktree Stacks

Each git worktree can run its own isolated Docker stack so you can work on
multiple branches in parallel without port or container-name conflicts.
`scripts/worktree-env.sh` generates a per-worktree `.env` with a unique
`COMPOSE_PROJECT_NAME` and a non-conflicting port block derived from a hash
of the worktree path.

```bash
# From inside any worktree (including the main checkout):
scripts/worktree-env.sh          # writes ./.env (refuses to overwrite)
scripts/worktree-env.sh --print  # preview without writing
scripts/worktree-env.sh --force  # overwrite an existing .env

docker compose up -d             # picks up .env automatically
```

The main checkout keeps the default ports (443/80/5432/...). Linked worktrees
get ports in the 20000+ range (e.g. HTTPS on `20409`, Mailpit UI on `27409`,
PostgreSQL on `25409`). Access the app at the `APP_FRONTEND_URL` printed by
the script.

`.env` is gitignored, so each worktree's values stay local.

### Docker Desktop on Windows: port-forward auto-recovery

Docker Desktop on Windows occasionally stops forwarding traffic from the
host to the WSL2 distro after sleep/hibernate cycles. Symptom: the
container's healthcheck (run inside the docker network) keeps reporting
healthy, but `curl https://localhost` from the host hangs indefinitely
and the PWA UI sits on "Loading…".

The fix is to restart the affected container so Docker Desktop rebuilds
the port forward (`docker compose restart php`). To automate this in
the background, run:

```bash
scripts/watch-port-forward.sh
```

The script polls `https://localhost/docs` every 30 seconds and restarts
the `php` service after 3 consecutive failures. Override defaults via
env vars (`POLL_INTERVAL`, `FAIL_THRESHOLD`, `WATCH_URL`,
`WATCH_SERVICE`, `PROBE_TIMEOUT`, `COOLDOWN`). Stop with Ctrl-C; intended
to run in its own terminal alongside `docker compose up`.

## Production (Docker Compose)

Use `compose.prod.yaml` with overrides:

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

Ensure all secrets are set to strong, unique values in production.

## Origin TLS behind Cloudflare

Production (`madori.app`) sits behind the Cloudflare proxy (orange cloud) with
the SSL/TLS mode set to **Full (strict)**: Cloudflare presents its edge
certificate to browsers and re-encrypts to the origin, validating the origin's
certificate. By default Caddy/FrankenPHP auto-provisions a Let's Encrypt
certificate for the origin, but **ACME renewal can fail behind the proxy** — the
HTTP-01 / TLS-ALPN-01 challenges are intercepted by Cloudflare — so a renewal
that silently fails would eventually lapse the origin cert and Full (strict)
would start returning **HTTP 526** errors.

The durable fix is a **Cloudflare Origin Certificate** (a long-lived cert, up to
15 years, trusted only by Cloudflare's edge — exactly what a proxied origin
needs). Caddy serves it via an explicit `tls` directive instead of ACME.

This is wired up but **gated** so it has zero effect until switched on:

- `api/frankenphp/Caddyfile` — the public host block and the analytics block
  contain `{$TLS_DIRECTIVE}`, which expands to nothing when the env var is unset
  (dev and pre-cutover prod keep automatic HTTPS). The internal `php` host is a
  separate **HTTP-only** block (`http://php`) sharing the routing via the `(app)`
  snippet — it must not carry the cert, because Caddy rejects a TLS policy on an
  HTTP `:80` listener. For the same reason `php:80` was dropped from `SERVER_NAME`
  in `compose.yaml`.
- `compose.prod.yaml` — the `php` service passes `TLS_DIRECTIVE` through and
  mounts the host `./certs` dir read-only at `/etc/caddy/origin`. `./certs` is
  gitignored and survives the deploy's `git reset --hard`.

### One-time cutover

1. **Create the Origin Certificate.** In the Cloudflare dashboard:
   *SSL/TLS → Origin Server → Create Certificate* (let Cloudflare generate the
   key; hostnames `madori.app` + `*.madori.app`; 15-year validity). Copy the
   **Origin Certificate** and **Private Key** PEM blocks.
2. **Place the files on the server** (key readable only by root):
   ```bash
   mkdir -p /opt/aura/certs
   # paste the cert into cert.pem and the key into key.pem
   chmod 600 /opt/aura/certs/key.pem
   ```
3. **Enable it** in `/opt/aura/.env`:
   ```
   TLS_DIRECTIVE=tls /etc/caddy/origin/cert.pem /etc/caddy/origin/key.pem
   ```
4. **Recreate the `php` container** to pick up the new env + Caddyfile:
   ```bash
   docker compose -f compose.yaml -f compose.prod.yaml up -d php
   ```
5. **Verify** the origin now serves the Cloudflare Origin CA cert and the site
   is still healthy through the proxy:
   ```bash
   echo | openssl s_client -connect 5.78.210.192:443 -servername madori.app 2>/dev/null \
     | openssl x509 -noout -issuer -subject        # issuer: Cloudflare, Inc.
   curl -sS -o /dev/null -w '%{http_code}\n' https://madori.app/   # 200
   ```

> The Caddyfile change ships in the `app-php` image, so the cutover requires the
> image built from a commit that contains `{$TLS_DIRECTIVE}` to be deployed
> first. Until step 3 sets the var, that deploy is a no-op for TLS.

**Rollback:** remove the `TLS_DIRECTIVE` line from `.env` and
`up -d php` again — Caddy reverts to automatic Let's Encrypt.

## Backups

The compose stack has two stateful pieces: the PostgreSQL database (volume
`database_data`) and user-uploaded media (volume `media_data`). Backups
are **application-managed** — `App\Service\BackupRunner`, exposed as the
`app:backup:run` console command — and run from two triggers, through the
same code path:

- **Nightly at 02:00 UTC** via the scheduler (`App\Scheduler\MainScheduleProvider`
  fires `App\Message\RunBackup`; the existing `worker` service handles it —
  no host cron, and a run missed during downtime is caught up on the next
  worker boot thanks to the stateful schedule).
- **Before every deploy**: `scripts/deploy.sh` runs the command in a
  one-off container of the incoming image and aborts the deploy if it fails.

```bash
docker compose -f compose.yaml -f compose.prod.yaml exec -T worker bin/console app:backup:run
# -> backups/db-20260610-020000.sql.gz      (pg_dump, gzip-compressed plain SQL)
# -> backups/media-20260610-020000.tar.gz   (tar of the media volume)
```

`pg_dump` (from `postgresql-client`, baked into the api image) connects to
the database over the compose network with the app's own Doctrine
credentials — no `exec` into the database container. The worker mounts the
media volume read-only and bind-mounts `./backups` to the host (gitignored),
so backup files are plain host files for `restore.sh` and off-box copies.

Configuration (parameters in `api/config/services.yaml`):

| Parameter                | Default              | Description |
|--------------------------|----------------------|-------------|
| `app.backup_dir`         | `%kernel.project_dir%/var/backups` | Where backup files are written (host-bound to `./backups` in compose) |
| `app.backup_max_backups` | `5`                  | Keep at most N db dumps and N media archives, deleting the oldest first (`0` disables). Nightly and pre-deploy backups share one pool, so this is the total ceiling per kind. |

### Restoring

`scripts/restore.sh` is the inverse (it stays a host-side shell script on
purpose — a restore stops the very containers an in-app command would run
in). It is **destructive** — it stops `php`/`worker`, drops and recreates
the database, replays the dump, and (optionally) replaces the media volume
contents — so it prompts for a literal `yes` unless `--force` is passed:

```bash
scripts/restore.sh backups/db-20260609-031500.sql.gz
scripts/restore.sh --media backups/media-20260609-031500.tar.gz backups/db-20260609-031500.sql.gz
scripts/restore.sh --force backups/db-20260609-031500.sql.gz   # no prompt (for scripted recovery)
```

Restore into a fresh droplet by starting the stack first
(`docker compose -f compose.yaml -f compose.prod.yaml up -d`), then
running the restore — the migration step on boot is harmless since the
dump replays the full schema over the recreated database.

### Scheduling

There is nothing to schedule on the host: the nightly run rides the same
scheduler + worker pair as task reminders and digests (see "Recurring
jobs" below). Every run — nightly or deploy-time — re-applies the
`app.backup_max_backups` cap, so the pool always converges back to the
newest 5 of each kind.

Backups written to the server's own disk don't survive the server
dying — copy `./backups` off-box (e.g. `rclone` to object storage, or
`scp` to another machine) from a host cron if you want that layer.

### DigitalOcean droplet snapshots

Enable [droplet snapshots/backups](https://docs.digitalocean.com/products/images/snapshots/)
as a belt-and-braces layer: they capture the whole disk (including the
Docker volumes) and make whole-droplet recovery trivial. They are **not**
a substitute for `pg_dump`, though — a snapshot of a running Postgres
data directory is only crash-consistent, and snapshots can't restore a
single database or be replayed into a different environment. Run both:
snapshots for disaster recovery, `app:backup:run` for clean, portable,
point-in-time database dumps.

## Kubernetes (Helm + Skaffold)

### Prerequisites
- Kubernetes cluster
- Helm 3
- Skaffold

### Deploy
```bash
cd helm
skaffold run
```

### Configuration
- Helm chart: `helm/api-platform/`
- Skaffold config: `helm/skaffold.yaml`
- Values override: `helm/skaffold-values.yaml`

## CI/CD

### Health Checks
The API container includes a health check that verifies the `/docs` endpoint is accessible:

```yaml
healthcheck:
  test: curl --insecure --fail https://localhost/docs || exit 1
  interval: 15s
  timeout: 5s
  retries: 20
  start_period: 60s
```

### Build Targets
- **API image**: Multi-stage Dockerfile at `api/Dockerfile`
- **PWA image**: Dockerfile at `pwa/Dockerfile`

## Releases

Releases are tagged on `main` using date-based build numbers (`YYYY.MM.DD.N`).

### Creating a Release

```bash
# Check if a tag already exists for today
git tag -l "$(date +%Y.%m.%d).*" --sort=-v:refname | head -1

# Tag and push
git tag 2026.04.12.1
git push origin 2026.04.12.1
```

### Release Checklist

1. All CI checks pass on `main` (tests, lint, E2E)
2. Docker images build successfully
3. Health checks pass in the compose environment
4. Tag the release and push the tag
5. Optionally create a GitHub release with changelog notes

See [`branching-and-releases.md`](branching-and-releases.md) for the full release strategy.

## Dependency Updates

Use the `update-deps.sh` script to update all project dependencies:

```bash
./update-deps.sh
```

## Scheduled background jobs

Recurring jobs are scheduled **in-process via [symfony/scheduler](https://symfony.com/doc/current/scheduler.html)** — there is no system cron, no extra container, and nothing to configure per environment. The schedule ([`api/src/Scheduler/MainScheduleProvider.php`](../../api/src/Scheduler/MainScheduleProvider.php)) materialises as a Messenger transport named `scheduler_default`, and the existing `worker` service consumes it alongside the `async` queue (`messenger:consume async scheduler_default`) in both the Compose stack and the Helm worker Deployment. Anywhere the worker runs, the schedule runs.

| Job | Cadence (UTC) | Purpose |
| --- | --- | --- |
| `DispatchTaskReminders` | Every 5 minutes | Creates in-app notifications for due task reminders and (for users on `notificationFrequency=realtime` with `emailNotificationsEnabled=true`) sends a per-reminder email. |
| `DispatchNotificationDigest('hourly')` | Hourly at minute 55 | Rolls up pending in-app notifications for users on `notificationFrequency=hourly` into a single grouped digest email. |
| `DispatchNotificationDigest('daily')` | Daily at 08:00 | Same as above for users on `notificationFrequency=daily`. |

Each tick dispatches a message through the normal Messenger pipeline; the handlers delegate to `App\Service\TaskReminderDispatcher` / `App\Service\NotificationDigestDispatcher`. The same services back the manual console commands, which remain available for one-off runs:

```bash
docker compose exec php bin/console app:tasks:reminders:dispatch
docker compose exec php bin/console app:notifications:dispatch-digest --period=hourly
```

Everything is idempotent: the digest path stamps each notification with `digestedAt` once shipped (reruns within the same window are no-ops), reminders are deduped by a unique index on (recipient, task, offset), and the realtime path skips users whose frequency is `hourly` or `daily`, so the two paths never double-deliver.

The schedule is **stateful** (last-run timestamps live in the app cache) so the worker's hourly `--time-limit` recycle — or a deploy — can't skip a tick that lands during the restart window; after longer downtime only the last missed run per job is replayed. A lock keeps multiple consumers from double-firing a tick, but with the default `LOCK_DSN=flock` and filesystem cache both stores are host-local — if you ever scale the worker beyond one replica, either keep a single replica consuming `scheduler_default` or point `LOCK_DSN` and `cache.app` at shared stores (the handlers are idempotent, so a duplicate tick is wasted work, not duplicate email). Inspect the schedule with `bin/console debug:scheduler`. See [`job-queue.md`](job-queue.md) for how to add a new recurring job.

## Web Push (VAPID)

Web Push delivery (#100) signs requests to the browser's push service with a VAPID key pair. The reminder dispatcher (`app:tasks:reminders:dispatch`) sends a Web Push to every registered device of a recipient who has `pushNotificationsEnabled=true`; subscriptions the push service rejects with 404/410 are pruned inline so dead endpoints don't accumulate. The PWA service worker that turns these payloads into desktop notifications is the remaining piece of #100 and lands in a follow-up.

| Env var | Purpose |
| --- | --- |
| `VAPID_PUBLIC_KEY` | Base64-url-encoded P-256 public key. Shipped to the PWA so `PushManager.subscribe()` can apply it. |
| `VAPID_PRIVATE_KEY` | Base64-url-encoded P-256 private key. **Server-only** — never exposed to clients. |
| `VAPID_SUBJECT` | `mailto:` or `https://` contact for push services to reach you about issues. |

Leaving any of the three slots empty disables push send: the dispatcher logs a warning, the in-app notification + email paths still run, and existing subscription rows are left untouched. This keeps a fresh checkout green without forcing every contributor to generate a key pair.

Generate a fresh pair with `web-push generate-vapid-keys` (Node) or any equivalent tool, store the private key as a Kubernetes Secret, and never rotate it without re-prompting users — public-key changes invalidate every existing subscription row.
