#!/usr/bin/env bash
#
# Production deploy, run ON the server by .github/workflows/deploy.yml
# (or by hand for a manual deploy). Sequence:
#
#   1. pull the new images
#   2. raise the maintenance page (Caddy @maintenance flag file)
#   3. back up the database + media (`bin/console app:backup:run` in a
#      one-off container of the INCOMING image — so the backup engine is
#      always the code about to be deployed, never a stale container)
#   4. swap the containers
#   5. wait for the stack to come back healthy
#   6. lower the maintenance page
#
# On ANY failure the script exits non-zero and leaves the maintenance page
# UP — a half-deployed stack should not serve traffic, and the workflow
# surfaces the failure. Fix forward or roll back (re-run with the previous
# IMAGES_TAG), then the next successful run lowers the page.
#
# Usage:
#   IMAGES_TAG=<commit-sha> scripts/deploy.sh
#
# Configuration (env vars):
#   IMAGES_TAG     REQUIRED. Image tag to deploy (the workflow passes the
#                  commit SHA; any previously pushed tag works for rollback).
#   COMPOSE_FILES  Compose files (default: "compose.yaml compose.prod.yaml")
#   HEALTH_TIMEOUT Seconds to wait for the stack to report healthy (default: 300)
#
# Notes:
#   - Only ever brings up database/php/worker/pwa/umami. A bare `up -d` would
#     also start mailpit and publish its ports publicly — never do that in
#     prod.
#   - IMAGES_TAG is persisted into .env so later manual `docker compose`
#     invocations keep operating on the deployed tag instead of :latest.
#   - Requires IMAGES_PREFIX in .env (e.g. ghcr.io/<owner>/) so image refs
#     resolve to the registry the workflow pushes to.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE_FILES="${COMPOSE_FILES:-compose.yaml compose.prod.yaml}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-300}"
SERVICES=(database php worker pwa umami)
# Health gate only on the app itself — analytics (umami) must never hold a
# deploy hostage or leave the maintenance page up.
CORE_SERVICES=(database php worker pwa)
# The umami service sits behind the `analytics` compose profile (so CI and
# casual dev `up` skip it); activate it for every prod compose invocation.
export COMPOSE_PROFILES="${COMPOSE_PROFILES:-analytics}"

if [ -z "${IMAGES_TAG:-}" ]; then
  echo "IMAGES_TAG is required (commit SHA of the images to deploy)" >&2
  exit 64
fi
export IMAGES_TAG

# Tag every Sentry event / log / trace with the release being deployed (the
# image tag = commit SHA), so Sentry can group issues by release and flag
# regressions. Releases are free on all Sentry plans. Exported so the
# `${SENTRY_RELEASE:-}` refs in compose.yaml (php + worker) pick it up; a
# SENTRY_RELEASE already set in the environment wins.
export SENTRY_RELEASE="${SENTRY_RELEASE:-$IMAGES_TAG}"

log() {
  printf '[deploy %s] %s\n' "$(date -Iseconds 2>/dev/null || date)" "$*"
}

compose() {
  local args=() f
  for f in $COMPOSE_FILES; do
    args+=(-f "$f")
  done
  docker compose "${args[@]}" "$@"
}

log "deploying tag $IMAGES_TAG"

# 1. Pull first, so the backup below can run on the incoming image and the
# swap later is quick. --no-build everywhere: the server never builds;
# images come from the registry the workflow pushed to.
log "pulling images"
compose pull -q "${SERVICES[@]}"

# 2. Maintenance page up. The flag lives in the maintenance_flag volume, so
# it survives the container swap below. Tolerate failure on the very first
# deploy from a pre-maintenance-mode image (no volume mount yet) — the flag
# is cosmetic there, the deploy itself is unaffected.
log "raising maintenance page"
compose exec -T php sh -c 'mkdir -p /app/var/maintenance && touch /app/var/maintenance/maintenance.on' \
  || log "WARN: could not raise maintenance flag (old image without maintenance support?)"

# 3. Pre-deploy backup, via the same engine as the nightly scheduler job
# (App\Service\BackupRunner; newest-MAX kept across both). Runs in a
# one-off container of the image we are ABOUT to deploy — `compose run`
# uses the freshly pulled tag plus the worker service's mounts (read-only
# media + host-bound ./backups), so this works even when the currently
# running containers predate the backup command. A failed backup aborts
# the deploy — never swap code without a fresh restore point.
log "running pre-deploy backup"
compose run --rm --no-deps -T worker bin/console app:backup:run

# 3.5. Ensure the umami analytics database exists (idempotent — umami keeps
# its tables out of the app database, in its own DB on the same instance).
# Best-effort — analytics must never block an app deploy.
log "ensuring umami database exists"
compose exec -T database sh -c \
  "psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -tAc \"SELECT 1 FROM pg_database WHERE datname = 'umami'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" umami" \
  || log "WARN: could not ensure umami database (analytics may be unavailable)"

# 4. Swap.
log "swapping containers"
compose up -d --no-build "${SERVICES[@]}"

# Persist the deployed tag so manual compose commands target it, not :latest.
if grep -q '^IMAGES_TAG=' .env 2>/dev/null; then
  sed -i "s|^IMAGES_TAG=.*|IMAGES_TAG=$IMAGES_TAG|" .env
else
  printf 'IMAGES_TAG=%s\n' "$IMAGES_TAG" >> .env
fi

# 5. Wait for health: php must be healthy (migrations ran, Caddy serving)
# and the worker running. `compose ps` is polled rather than `up --wait`
# so the failure mode is a readable timeout, not a hung compose call.
log "waiting for stack health (timeout ${HEALTH_TIMEOUT}s)"
deadline=$(( $(date +%s) + HEALTH_TIMEOUT ))
while true; do
  unhealthy="$(compose ps --format '{{.Service}} {{.Health}}' "${CORE_SERVICES[@]}" \
    | awk '$2 != "healthy" {print $1}' || true)"
  if [ -z "$unhealthy" ]; then
    break
  fi
  if [ "$(date +%s)" -ge "$deadline" ]; then
    log "ERROR: stack did not become healthy in ${HEALTH_TIMEOUT}s (pending: $(echo "$unhealthy" | tr '\n' ' '))"
    compose ps
    exit 1
  fi
  sleep 5
done

# 6. Maintenance page down — only reached when everything above succeeded.
log "lowering maintenance page"
compose exec -T php rm -f /app/var/maintenance/maintenance.on

log "deploy of $IMAGES_TAG complete"
compose ps --format 'table {{.Service}}\t{{.Image}}\t{{.Status}}'
