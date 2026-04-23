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

## Production (Docker Compose)

Use `compose.prod.yaml` with overrides:

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

Ensure all secrets are set to strong, unique values in production.

## Kubernetes (Helm + Skaffold)

### Prerequisites
- Kubernetes cluster
- Helm 3
- Skaffold

### Deploy
```bash
skaffold run
```

### Configuration
- Helm chart: `helm/api-platform/`
- Skaffold config: `skaffold.yaml`
- Values override: `skaffold-values.yaml`

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

See `docs/branching-and-releases.md` for the full release strategy.

## Dependency Updates

Use the `update-deps.sh` script to update all project dependencies:

```bash
./update-deps.sh
```
