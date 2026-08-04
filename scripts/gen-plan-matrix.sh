#!/usr/bin/env bash
#
# Regenerate the PWA's copy of the plan entitlement matrix from the PHP
# catalog (api/src/Billing/PlanCatalog.php).
#
# The public pricing page is statically built with no API reachable, so it
# carries its own copy of what each plan grants. Generating that copy is what
# stops it drifting from the server. CI runs this and fails on a diff.
#
#   scripts/gen-plan-matrix.sh
#
# The api container only bind-mounts ./api, so it cannot write into pwa/ — the
# command prints to stdout and the redirect happens here on the host.
set -euo pipefail

cd "$(dirname "$0")/.."

OUT="pwa/lib/planEntitlements.generated.ts"
COMPOSE=(docker compose -f compose.yaml -f compose.override.yaml)

if ! "${COMPOSE[@]}" ps --status running --services 2>/dev/null | grep -qx php; then
  echo "The 'php' service isn't running. Start it first:" >&2
  echo "  docker compose up -d php" >&2
  exit 1
fi

# Write via a temp file so a failing command can't truncate the committed copy
# into an empty file that then reads as "no drift".
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

"${COMPOSE[@]}" exec -T php bin/console app:plans:export-matrix > "$tmp"

if [ ! -s "$tmp" ]; then
  echo "Generator produced no output; leaving $OUT untouched." >&2
  exit 1
fi

mv "$tmp" "$OUT"
trap - EXIT
echo "Wrote $OUT"
