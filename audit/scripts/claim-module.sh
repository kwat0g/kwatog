#!/usr/bin/env bash
# Atomically claims a module for the current session.
# Usage: claim-module.sh <domain> <module> [stale-hours, default 6]
# Exit 0 + "CLAIMED"/"RECLAIMED"  -> proceed, you own this module
# Exit 1 + "LOCKED"               -> another session owns it, pick a different module
# Exit 3 + error                  -> module folder doesn't exist, check the name

set -e
DOMAIN="$1"
MODULE="$2"
STALE_HOURS="${3:-6}"
AUDIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIR="${AUDIT_DIR}/domains/${DOMAIN}/${MODULE}"
LOCK="${DIR}/.lock"

if [ ! -d "$DIR" ]; then
  echo "ERROR: module folder not found: $DIR" >&2
  exit 3
fi

# mkdir is atomic at the filesystem level: if two processes race to create
# the same directory, exactly one wins. This is what makes the claim safe
# without any external coordination.
if mkdir "$LOCK" 2>/dev/null; then
  date -u +"%Y-%m-%dT%H:%M:%SZ" > "$LOCK/claimed-at.txt"
  echo "${CLAUDE_SESSION_ID:-unknown-session}" > "$LOCK/claimed-by.txt"
  echo "CLAIMED"
  exit 0
fi

# Lock already exists - check if it's stale (likely a crashed/abandoned session)
if [ -f "$LOCK/claimed-at.txt" ]; then
  CLAIMED_AT=$(cat "$LOCK/claimed-at.txt")
  CLAIMED_EPOCH=$(date -d "$CLAIMED_AT" +%s 2>/dev/null || echo 0)
  NOW_EPOCH=$(date -u +%s)
  AGE_HOURS=$(( (NOW_EPOCH - CLAIMED_EPOCH) / 3600 ))
  if [ "$AGE_HOURS" -ge "$STALE_HOURS" ]; then
    echo "STALE lock found (${AGE_HOURS}h old, likely a crashed session) - reclaiming"
    rm -rf "$LOCK"
    mkdir "$LOCK"
    date -u +"%Y-%m-%dT%H:%M:%SZ" > "$LOCK/claimed-at.txt"
    echo "${CLAUDE_SESSION_ID:-unknown-session}" > "$LOCK/claimed-by.txt"
    echo "RECLAIMED"
    exit 0
  fi
fi

echo "LOCKED"
exit 1
