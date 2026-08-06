#!/usr/bin/env bash
#
# claude-autoretry.sh — auto-retry wrapper for Claude Code CLI
#
# Runs `claude -p` and automatically sends "continue the task" (via --continue)
# whenever Claude Code hits the "empty or malformed response (HTTP 200)" error
# or similar transient/proxy-mangled failures — so you don't have to retype it.
#
# Usage:
#   ./claude-autoretry.sh "your initial prompt" [max_retries] [sleep_seconds]
#
# Examples:
#   ./claude-autoretry.sh "Run the ogami-discovery-audit.md phase 3 prompt"
#   ./claude-autoretry.sh "Continue the audit" 30 10
#
# Exit codes:
#   0  = completed without hitting the error again
#   1  = gave up after max_retries
#   *  = Claude exited with a real (non-transient) error — passed through
#
set -uo pipefail

PROMPT="${1:?Usage: $0 \"initial prompt\" [max_retries] [sleep_seconds]}"
MAX_RETRIES="${2:-15}"
SLEEP_BETWEEN="${3:-5}"
LOG="/tmp/claude-autoretry-$(date +%Y%m%d-%H%M%S).log"

# Pattern that identifies the transient/proxy-mangled failure worth retrying.
# Add more patterns here (separated by |) if you see other transient wordings.
RETRY_PATTERN='empty or malformed response|proxy or gateway intercepting|Socket is closed|Connection closed mid-response|Server error mid-response|Response stalled mid-stream'

echo "Logging full output to: $LOG"
echo

# Quick sanity check: warn if a stray proxy/base-url env var is set, since
# that's the most common real cause of "check for a proxy or gateway
# intercepting the request".
if [[ -n "${ANTHROPIC_BASE_URL:-}" ]]; then
  echo "⚠️  ANTHROPIC_BASE_URL is set to: $ANTHROPIC_BASE_URL"
  echo "    If this points at an old proxy setup (e.g. a Poe API proxy) that's"
  echo "    no longer running correctly, that's likely your real root cause —"
  echo "    unset it and see if the error stops happening at all."
  echo
fi

attempt=1
first_run=true

while (( attempt <= MAX_RETRIES )); do
  if $first_run; then
    cmd=(claude -p "$PROMPT" --output-format stream-json --verbose --include-partial-messages)
    first_run=false
  else
    cmd=(claude -p "continue the task" --continue --output-format stream-json --verbose --include-partial-messages)
  fi

  echo "[$(date +%T)] Attempt $attempt/$MAX_RETRIES: ${cmd[*]}" | tee -a "$LOG"

  output=$("${cmd[@]}" 2>&1 | tee -a "$LOG")
  status="${PIPESTATUS[0]}"

  if echo "$output" | grep -qiE "$RETRY_PATTERN"; then
    echo "[$(date +%T)] Detected a transient/malformed-response error. Retrying in ${SLEEP_BETWEEN}s..." | tee -a "$LOG"
    sleep "$SLEEP_BETWEEN"
    attempt=$((attempt + 1))
    continue
  fi

  if [[ "$status" -ne 0 ]]; then
    echo "[$(date +%T)] Claude exited with status $status (not the transient error). Stopping — check the log." | tee -a "$LOG"
    exit "$status"
  fi

  echo "[$(date +%T)] Completed without hitting the error again. Done." | tee -a "$LOG"
  exit 0
done

echo "[$(date +%T)] Gave up after $MAX_RETRIES attempts. See $LOG for details." | tee -a "$LOG"
exit 1
