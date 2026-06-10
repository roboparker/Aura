#!/usr/bin/env bash
#
# Restore a backup created by `bin/console app:backup:run`
# (App\Service\BackupRunner — nightly via the scheduler, plus one before
# every deploy). Stays a host-side script on purpose: a restore stops the
# very containers an in-app command would run in.
#
# DESTRUCTIVE: drops and recreates the database, and (with --media)
# replaces the contents of the media volume. Prompts for confirmation
# unless --force is passed.
#
# Usage:
#   scripts/restore.sh backups/db-20260609-031500.sql.gz
#   scripts/restore.sh --media backups/media-20260609-031500.tar.gz backups/db-20260609-031500.sql.gz
#   scripts/restore.sh --force backups/db-20260609-031500.sql.gz
#
# Configuration (env vars):
#   COMPOSE_FILES   Space-separated compose files (default: "compose.yaml compose.prod.yaml")
#   POSTGRES_USER   Database user (default: app)
#   POSTGRES_DB     Database name (default: app)
#
# The stack must be running. php and worker are stopped for the duration
# of the restore so nothing writes mid-restore, then started again.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

COMPOSE_FILES="${COMPOSE_FILES:-compose.yaml compose.prod.yaml}"
POSTGRES_USER="${POSTGRES_USER:-app}"
POSTGRES_DB="${POSTGRES_DB:-app}"

log() {
  printf '[restore %s] %s\n' "$(date -Iseconds 2>/dev/null || date)" "$*"
}

compose() {
  local args=() f
  for f in $COMPOSE_FILES; do
    args+=(-f "$f")
  done
  docker compose "${args[@]}" "$@"
}

db_dump=""
media_archive=""
force=0
while [ $# -gt 0 ]; do
  case "$1" in
    --force) force=1 ;;
    --media)
      shift
      [ $# -gt 0 ] || { echo "--media needs a file argument" >&2; exit 2; }
      media_archive="$1"
      ;;
    -h|--help)
      sed -n '2,21p' "$0"
      exit 0
      ;;
    -*) echo "unknown arg: $1" >&2; exit 2 ;;
    *)
      [ -z "$db_dump" ] || { echo "unexpected extra argument: $1" >&2; exit 2; }
      db_dump="$1"
      ;;
  esac
  shift
done

if [ -z "$db_dump" ]; then
  echo "usage: scripts/restore.sh [--force] [--media <media-*.tar.gz>] <db-*.sql.gz>" >&2
  exit 2
fi
[ -f "$db_dump" ] || { echo "no such file: $db_dump" >&2; exit 1; }
if [ -n "$media_archive" ]; then
  [ -f "$media_archive" ] || { echo "no such file: $media_archive" >&2; exit 1; }
fi

# Resolve backup paths to absolute before cd-ing to the repo root.
db_dump="$(cd "$(dirname "$db_dump")" && pwd)/$(basename "$db_dump")"
if [ -n "$media_archive" ]; then
  media_archive="$(cd "$(dirname "$media_archive")" && pwd)/$(basename "$media_archive")"
fi
cd "$ROOT"

if [ "$force" = "0" ]; then
  echo "This will DROP and recreate database '$POSTGRES_DB' from:"
  echo "  $db_dump"
  if [ -n "$media_archive" ]; then
    echo "and REPLACE the media volume contents from:"
    echo "  $media_archive"
  fi
  printf 'Type "yes" to continue: '
  read -r answer
  if [ "$answer" != "yes" ]; then
    echo "aborted." >&2
    exit 1
  fi
fi

log "stopping php and worker so nothing writes during the restore"
compose stop php worker

log "dropping and recreating database '$POSTGRES_DB'"
compose exec -T database psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 <<SQL
DROP DATABASE IF EXISTS "$POSTGRES_DB" WITH (FORCE);
CREATE DATABASE "$POSTGRES_DB" OWNER "$POSTGRES_USER";
SQL

log "restoring database from $db_dump"
gunzip -c "$db_dump" | compose exec -T database psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 --quiet

if [ -n "$media_archive" ]; then
  # php is stopped, so untar through a throwaway container on the same
  # volumes (--no-deps: don't restart anything; entrypoint bypassed so
  # the image's DB-wait/migrate logic doesn't run). Existing media is
  # cleared first so the volume matches the archive exactly.
  log "restoring media volume from $media_archive"
  gunzip -c "$media_archive" | compose run --rm --no-deps -T --entrypoint sh php \
    -c 'rm -rf /app/var/media/* && tar -xf - -C /app/var'
fi

log "starting php and worker"
compose up -d php worker

log "done"
