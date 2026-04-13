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
| `SERVER_NAME`                  | `localhost`              | Server hostname          |
| `POSTGRES_USER`                | `app`                    | Database user            |
| `POSTGRES_PASSWORD`            | `!ChangeMe!`             | Database password        |
| `POSTGRES_DB`                  | `app`                    | Database name            |
| `CADDY_MERCURE_JWT_SECRET`     | `!ChangeThisMercure...`  | Mercure JWT secret       |
| `HTTPS_PORT`                   | `443`                    | HTTPS port               |
| `HTTP_PORT`                    | `80`                     | HTTP port                |

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
