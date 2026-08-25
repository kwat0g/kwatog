#!/usr/bin/env bash
# Releases a claimed module: updates its status.md, removes the lock.
# Usage: release-module.sh <domain> <module> "<new status line, e.g. '✅ Verified'>"

set -e
DOMAIN="$1"
MODULE="$2"
shift 2
NEW_STATUS="$*"
AUDIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIR="${AUDIT_DIR}/domains/${DOMAIN}/${MODULE}"
STATUS_FILE="${DIR}/status.md"
LOCK="${DIR}/.lock"

if [ ! -f "$STATUS_FILE" ]; then
  echo "ERROR: status file not found: $STATUS_FILE" >&2
  exit 3
fi

TODAY=$(date -u +"%Y-%m-%d")

TMP=$(mktemp)
awk -v new="status: ${NEW_STATUS}" -v ls="last_session: ${TODAY}" '
  /^status:/       { print new; next }
  /^last_session:/ { print ls; next }
  { print }
' "$STATUS_FILE" > "$TMP"
mv "$TMP" "$STATUS_FILE"

rm -rf "$LOCK"
echo "RELEASED ${DOMAIN}/${MODULE} with status: ${NEW_STATUS}"
