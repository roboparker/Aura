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

## Backups

The compose stack has two stateful pieces: the PostgreSQL database (volume
`database_data`) and user-uploaded media (volume `media_data`, mounted at
`/app/var/media` in the `php` container). `scripts/backup.sh` snapshots
both into timestamped files and prunes old ones:

```bash
scripts/backup.sh
# -> backups/db-20260609-031500.sql.gz      (pg_dump | gzip)
# -> backups/media-20260609-031500.tar.gz   (tar of the media volume)
```

The stack must be running — the dump goes through `docker compose exec`.
Configuration via env vars:

| Variable         | Default                          | Description |
|------------------|----------------------------------|-------------|
| `BACKUP_DIR`     | `./backups`                      | Where backup files are written |
| `RETENTION_DAYS` | `14`                             | Delete backups older than N days (`0` disables pruning) |
| `COMPOSE_FILES`  | `compose.yaml compose.prod.yaml` | Compose files passed as `-f` flags |
| `POSTGRES_USER`  | `app`                            | Database user for `pg_dump`/`psql` |
| `POSTGRES_DB`    | `app`                            | Database name |

### Restoring

`scripts/restore.sh` is the inverse. It is **destructive** — it stops
`php`/`worker`, drops and recreates the database, replays the dump, and
(optionally) replaces the media volume contents — so it prompts for a
literal `yes` unless `--force` is passed:

```bash
scripts/restore.sh backups/db-20260609-031500.sql.gz
scripts/restore.sh --media backups/media-20260609-031500.tar.gz backups/db-20260609-031500.sql.gz
scripts/restore.sh --force backups/db-20260609-031500.sql.gz   # no prompt (for scripted recovery)
```

Restore into a fresh droplet by starting the stack first
(`docker compose -f compose.yaml -f compose.prod.yaml up -d`), then
running the restore — the migration step on boot is harmless since the
dump replays the full schema over the recreated database.

### Scheduling with cron

Nightly at 03:10, keeping 14 days, logging to a file:

```cron
10 3 * * * cd /opt/aura && BACKUP_DIR=/var/backups/aura scripts/backup.sh >> /var/log/aura-backup.log 2>&1
```

Backups written to the droplet's own disk don't survive the droplet
dying — copy them off-box (e.g. `rclone`/`s3cmd` to DigitalOcean Spaces,
or `scp` to another machine) as a second cron step.

### DigitalOcean droplet snapshots

Enable [droplet snapshots/backups](https://docs.digitalocean.com/products/images/snapshots/)
as a belt-and-braces layer: they capture the whole disk (including the
Docker volumes) and make whole-droplet recovery trivial. They are **not**
a substitute for `pg_dump`, though — a snapshot of a running Postgres
data directory is only crash-consistent, and snapshots can't restore a
single database or be replayed into a different environment. Run both:
snapshots for disaster recovery, `scripts/backup.sh` for clean,
portable, point-in-time database dumps.

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
  timeout: 5s
  retries: 5
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

Two console commands need to run on a cron in production:

| Command | Recommended cadence | Purpose |
| --- | --- | --- |
| `bin/console app:tasks:reminders:dispatch` | Every 5 minutes | Creates in-app notifications for due task reminders and (for users on `notificationFrequency=realtime` with `emailNotificationsEnabled=true`) sends a per-reminder email. |
| `bin/console app:notifications:dispatch-digest --period=hourly` | Hourly at minute 55 | Rolls up pending in-app notifications for users on `notificationFrequency=hourly` into a single grouped digest email. |
| `bin/console app:notifications:dispatch-digest --period=daily` | Daily at 08:00 UTC | Same as above for users on `notificationFrequency=daily`. |

The digest commands stamp each notification with `digestedAt` once shipped, so reruns within the same window are no-ops. The realtime path skips users whose frequency is `hourly` or `daily`, so the two paths never double-deliver.

## Web Push (VAPID)

Web Push delivery (#100) signs requests to the browser's push service with a VAPID key pair. The reminder dispatcher (`app:tasks:reminders:dispatch`) sends a Web Push to every registered device of a recipient who has `pushNotificationsEnabled=true`; subscriptions the push service rejects with 404/410 are pruned inline so dead endpoints don't accumulate. The PWA service worker that turns these payloads into desktop notifications is the remaining piece of #100 and lands in a follow-up.

| Env var | Purpose |
| --- | --- |
| `VAPID_PUBLIC_KEY` | Base64-url-encoded P-256 public key. Shipped to the PWA so `PushManager.subscribe()` can apply it. |
| `VAPID_PRIVATE_KEY` | Base64-url-encoded P-256 private key. **Server-only** — never exposed to clients. |
| `VAPID_SUBJECT` | `mailto:` or `https://` contact for push services to reach you about issues. |

Leaving any of the three slots empty disables push send: the dispatcher logs a warning, the in-app notification + email paths still run, and existing subscription rows are left untouched. This keeps a fresh checkout green without forcing every contributor to generate a key pair.

Generate a fresh pair with `web-push generate-vapid-keys` (Node) or any equivalent tool, store the private key as a Kubernetes Secret, and never rotate it without re-prompting users — public-key changes invalidate every existing subscription row.
