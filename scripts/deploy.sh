#!/usr/bin/env bash
#
# Production deploy, run ON the server by .github/workflows/deploy.yml
# (or by hand for a manual deploy). Sequence:
#
#   1. raise the maintenance page (Caddy @maintenance flag file)
#   2. back up the database + media (scripts/backup.sh)
#   3. pull the new images and swap the containers
#   4. wait for the stack to come back healthy
#   5. lower the maintenance page
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
#   - Only ever brings up database/php/worker/pwa. A bare `up -d` would also
#     start mailpit and publish its ports publicly — never do that in prod.
#   - IMAGES_TAG is persisted into .env so later manual `docker compose`
#     invocations keep operating on the deployed tag instead of :latest.
#   - Requires IMAGES_PREFIX in .env (e.g. ghcr.io/<owner>/) so image refs
#     resolve to the registry the workflow pushes to.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE_FILES="${COMPOSE_FILES:-compose.yaml compose.prod.yaml}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-300}"
SERVICES=(database php worker pwa)

if [ -z "${IMAGES_TAG:-}" ]; then
  echo "IMAGES_TAG is required (commit SHA of the images to deploy)" >&2
  exit 64
fi
export IMAGES_TAG

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

# 1. Maintenance page up. The flag lives in the maintenance_flag volume, so
# it survives the container swap below. Tolerate failure on the very first
# deploy from a pre-maintenance-mode image (no volume mount yet) — the flag
# is cosmetic there, the deploy itself is unaffected.
log "raising maintenance page"
compose exec -T php sh -c 'mkdir -p /app/var/maintenance && touch /app/var/maintenance/maintenance.on' \
  || log "WARN: could not raise maintenance flag (old image without maintenance support?)"

# 2. Pre-deploy backup. Uses the same retention pool as the nightly cron
# (MAX_BACKUPS newest kept). A failed backup aborts the deploy — we never
# swap code without a fresh restore point.
log "running pre-deploy backup"
COMPOSE_FILES="$COMPOSE_FILES" "$ROOT/scripts/backup.sh"

# 3. Pull and swap. --no-build: the server never builds; images come from
# the registry the workflow pushed to.
log "pulling images"
compose pull -q "${SERVICES[@]}"

log "swapping containers"
compose up -d --no-build "${SERVICES[@]}"

# Persist the deployed tag so manual compose commands target it, not :latest.
if grep -q '^IMAGES_TAG=' .env 2>/dev/null; then
  sed -i "s|^IMAGES_TAG=.*|IMAGES_TAG=$IMAGES_TAG|" .env
else
  printf 'IMAGES_TAG=%s\n' "$IMAGES_TAG" >> .env
fi

# 4. Wait for health: php must be healthy (migrations ran, Caddy serving)
# and the worker running. `compose ps` is polled rather than `up --wait`
# so the failure mode is a readable timeout, not a hung compose call.
log "waiting for stack health (timeout ${HEALTH_TIMEOUT}s)"
deadline=$(( $(date +%s) + HEALTH_TIMEOUT ))
while true; do
  unhealthy="$(compose ps --format '{{.Service}} {{.Health}}' "${SERVICES[@]}" \
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

# 5. Maintenance page down — only reached when everything above succeeded.
log "lowering maintenance page"
compose exec -T php rm -f /app/var/maintenance/maintenance.on

log "deploy of $IMAGES_TAG complete"
compose ps --format 'table {{.Service}}\t{{.Image}}\t{{.Status}}'
