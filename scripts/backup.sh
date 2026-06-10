#!/usr/bin/env bash
#
# Back up the two stateful pieces of the compose stack: a gzipped pg_dump
# of the PostgreSQL database and a gzipped tar of the media volume (user
# uploads), written to timestamped files with simple age-based retention.
#
# Usage:
#   scripts/backup.sh
#   BACKUP_DIR=/var/backups/aura RETENTION_DAYS=30 scripts/backup.sh
#
# Configuration (env vars):
#   BACKUP_DIR      Where backups are written (default: ./backups)
#   RETENTION_DAYS  Delete backups older than N days (default: 14; 0 disables)
#   COMPOSE_FILES   Space-separated compose files (default: "compose.yaml compose.prod.yaml")
#   POSTGRES_USER   Database user (default: app)
#   POSTGRES_DB     Database name (default: app)
#
# The stack must be running (the dump goes through `docker compose exec`).
# Intended to run from cron on the droplet — see docs/developer/deployment.md.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

BACKUP_DIR="${BACKUP_DIR:-$ROOT/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
COMPOSE_FILES="${COMPOSE_FILES:-compose.yaml compose.prod.yaml}"
POSTGRES_USER="${POSTGRES_USER:-app}"
POSTGRES_DB="${POSTGRES_DB:-app}"

log() {
  printf '[backup %s] %s\n' "$(date -Iseconds 2>/dev/null || date)" "$*"
}

compose() {
  local args=() f
  for f in $COMPOSE_FILES; do
    args+=(-f "$f")
  done
  docker compose "${args[@]}" "$@"
}

# Resolve BACKUP_DIR to an absolute path before cd-ing to the repo root,
# so a relative BACKUP_DIR stays relative to the caller's cwd.
mkdir -p "$BACKUP_DIR"
BACKUP_DIR="$(cd "$BACKUP_DIR" && pwd)"
cd "$ROOT"

timestamp="$(date +%Y%m%d-%H%M%S)"

# Database dump. Write to a .tmp first and rename only on success, so a
# failed dump (set -o pipefail catches pg_dump errors through the gzip
# pipe) never leaves a truncated file that looks like a valid backup.
db_file="$BACKUP_DIR/db-$timestamp.sql.gz"
log "dumping database '$POSTGRES_DB' to $db_file"
compose exec -T database pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" | gzip > "$db_file.tmp"
mv "$db_file.tmp" "$db_file"

# Media volume (user uploads). The volume is mounted in the php container
# at /app/var/media, so tar it from there rather than guessing the
# project-prefixed volume name.
media_file="$BACKUP_DIR/media-$timestamp.tar.gz"
log "archiving media volume to $media_file"
compose exec -T php tar -cf - -C /app/var media | gzip > "$media_file.tmp"
mv "$media_file.tmp" "$media_file"

# Retention: drop backups older than RETENTION_DAYS. Only touches files
# matching our own naming pattern, so anything else in BACKUP_DIR is safe.
if [ "$RETENTION_DAYS" -gt 0 ]; then
  log "pruning backups older than $RETENTION_DAYS day(s)"
  find "$BACKUP_DIR" -maxdepth 1 -type f \
    \( -name 'db-*.sql.gz' -o -name 'media-*.tar.gz' \) \
    -mtime +"$RETENTION_DAYS" -print -delete
fi

log "done: $db_file ($(du -h "$db_file" | cut -f1)), $media_file ($(du -h "$media_file" | cut -f1))"
