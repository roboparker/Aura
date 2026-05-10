#!/bin/sh
# Auto-recovers Docker Desktop's WSL→Windows port-forward bridge by
# restarting the php container when the published port stops
# answering from the host.
#
# Why this exists: Docker Desktop on Windows occasionally stops
# forwarding traffic from the Windows host to the WSL2 distro after
# sleep/hibernate cycles. The container's own healthcheck (running
# inside the network) keeps reporting healthy, but `curl
# https://localhost` from the host hangs. Restarting the php
# container forces Docker to rebuild the port-forward.
#
# Usage:
#   scripts/watch-port-forward.sh
#   POLL_INTERVAL=10 FAIL_THRESHOLD=2 scripts/watch-port-forward.sh
#   WATCH_URL=https://localhost/docs scripts/watch-port-forward.sh
#
# Stop with Ctrl-C. Intended to run in its own terminal alongside
# `docker compose up`.

set -e

URL="${WATCH_URL:-https://localhost/docs}"
SERVICE="${WATCH_SERVICE:-php}"
POLL_INTERVAL="${POLL_INTERVAL:-30}"
FAIL_THRESHOLD="${FAIL_THRESHOLD:-3}"
PROBE_TIMEOUT="${PROBE_TIMEOUT:-5}"
COOLDOWN="${COOLDOWN:-30}"

log() {
  printf '[watch-port-forward %s] %s\n' "$(date -Iseconds 2>/dev/null || date)" "$*"
}

log "watching $URL (poll=${POLL_INTERVAL}s, threshold=${FAIL_THRESHOLD} fails) — Ctrl-C to stop"

fails=0
while :; do
  if curl -k -fsS -o /dev/null --max-time "$PROBE_TIMEOUT" "$URL"; then
    if [ "$fails" -gt 0 ]; then
      log "probe ok again after $fails failure(s)"
    fi
    fails=0
  else
    fails=$((fails + 1))
    log "probe failed ($fails/$FAIL_THRESHOLD): $URL"
    if [ "$fails" -ge "$FAIL_THRESHOLD" ]; then
      log "restarting service '$SERVICE' to rebuild Docker Desktop's port-forward..."
      if docker compose restart "$SERVICE"; then
        log "restart issued; cooling down ${COOLDOWN}s before re-probing"
        sleep "$COOLDOWN"
      else
        log "docker compose restart $SERVICE failed; will keep probing"
      fi
      fails=0
    fi
  fi
  sleep "$POLL_INTERVAL"
done
