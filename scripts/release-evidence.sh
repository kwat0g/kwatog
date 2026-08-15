#!/usr/bin/env bash
# release-evidence.sh — reproducible, scratch-only release evidence harness.
#
# This script deliberately does not choose a database, compose project, user,
# upload target, or production endpoint.  A real run must provide every target
# explicitly and acknowledge that the database is disposable.  Results are
# written as timestamped JSON/text artifacts; an absent external run is never
# represented as a pass.

set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
MODE="contract"
for arg in "$@"; do
    case "$arg" in
        --contract) MODE="contract" ;;
        --run) MODE="run" ;;
        --help|-h) sed -n '2,15p' "$0"; exit 0 ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

die() { echo "release evidence: $*" >&2; exit 2; }

if [[ "$MODE" == contract ]]; then
    command -v bash >/dev/null || die "bash is required"
    command -v curl >/dev/null || die "curl is required"
    [[ -f "$ROOT_DIR/scripts/db-restore.sh" ]] || die "restore helper missing"
    [[ -f "$ROOT_DIR/docker-compose.prod.yml" ]] || die "production compose missing"
    [[ -f "$ROOT_DIR/.github/workflows/deploy.yml" ]] || die "deploy workflow missing"
    bash -n "$ROOT_DIR/scripts/db-restore.sh" "$ROOT_DIR/scripts/deploy-update.sh" "$0"
    echo '{"harness":"release-evidence","mode":"contract","status":"pass","proof":"contract-only; no restore or production claim"}'
    exit 0
fi

: "${BACKUP_FILE:?BACKUP_FILE must point to a supplied dump}"
: "${SCRATCH_DB:?SCRATCH_DB is required (must be a disposable name)}"
: "${SCRATCH_CONFIRM:?SCRATCH_CONFIRM must equal I_UNDERSTAND_SCRATCH_ONLY}"
: "${EVIDENCE_DIR:?EVIDENCE_DIR is required; use an explicit scratch/artifact path}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${DB_HOST:?DB_HOST is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"

[[ "$SCRATCH_CONFIRM" == I_UNDERSTAND_SCRATCH_ONLY ]] || die "missing scratch-only confirmation"
[[ -f "$BACKUP_FILE" ]] || die "backup file does not exist: $BACKUP_FILE"
[[ "$SCRATCH_DB" =~ ^ogami_release_evidence_[a-z0-9_]+$ ]] || die "SCRATCH_DB must match ogami_release_evidence_[a-z0-9_]+"
[[ "$DB_DATABASE" == "$SCRATCH_DB" ]] || die "DB_DATABASE must equal SCRATCH_DB"
[[ "$DB_HOST" != production && "$DB_HOST" != prod && "$DB_HOST" != prod-db ]] || die "production DB host refused"
[[ "$EVIDENCE_DIR" != / && "$EVIDENCE_DIR" != "$ROOT_DIR" ]] || die "broad evidence path refused"
mkdir -p "$EVIDENCE_DIR"

RUN_TS="$(date -u +%Y%m%dT%H%M%SZ)"
REPORT="$EVIDENCE_DIR/release-evidence-$RUN_TS.json"
LOG="$EVIDENCE_DIR/release-evidence-$RUN_TS.log"
exec > >(tee "$LOG") 2>&1

STATUS=pass
RESULTS=()
record() {
    local name="$1" state="$2" detail="$3"
    RESULTS+=("$name|$state|$detail")
    [[ "$state" == pass ]] || STATUS=fail
}
run_step() {
    local name="$1"; shift
    local output
    if output="$("$@" 2>&1)"; then
        printf '%s\n' "$output"
        record "$name" pass "completed"
    else
        printf '%s\n' "$output" >&2
        record "$name" fail "command failed"
    fi
}

echo "release evidence run $RUN_TS (scratch database: $SCRATCH_DB)"
echo "No production endpoint or production database is permitted by this harness."

# db-restore.sh is destructive by design; the guards above make its target a
# uniquely named scratch DB and require an explicit acknowledgement.
export DB_HOST DB_USERNAME DB_PASSWORD
export DB_PORT="${DB_PORT:-5432}"
export DB_DATABASE="$SCRATCH_DB"
run_step restore "$ROOT_DIR/scripts/db-restore.sh" --yes "$BACKUP_FILE"

if [[ -n "${API_HEALTH_URL:-}" ]]; then
    run_step api_health curl -fsS --max-time "${HTTP_TIMEOUT_SECONDS:-15}" "$API_HEALTH_URL"
else
    record api_health not_run "API_HEALTH_URL was not supplied"
fi
if [[ -n "${AUTH_CHECK_COMMAND:-}" ]]; then
    run_step authenticated_api bash -c "$AUTH_CHECK_COMMAND"
else
    record authenticated_api not_run "AUTH_CHECK_COMMAND was not supplied"
fi
if [[ -n "${QUEUE_CHECK_COMMAND:-}" ]]; then
    run_step queue bash -c "$QUEUE_CHECK_COMMAND"
else
    record queue not_run "QUEUE_CHECK_COMMAND was not supplied"
fi
if [[ -n "${SCHEDULER_CHECK_COMMAND:-}" ]]; then
    run_step scheduler bash -c "$SCHEDULER_CHECK_COMMAND"
else
    record scheduler not_run "SCHEDULER_CHECK_COMMAND was not supplied"
fi
if [[ -n "${UPLOAD_CHECK_COMMAND:-}" ]]; then
    run_step durable_upload bash -c "$UPLOAD_CHECK_COMMAND"
else
    record durable_upload not_run "UPLOAD_CHECK_COMMAND was not supplied"
fi
if [[ -n "${MIGRATION_CHECK_COMMAND:-}" ]]; then
    run_step migration_upgrade_rollback bash -c "$MIGRATION_CHECK_COMMAND"
else
    record migration_upgrade_rollback not_run "MIGRATION_CHECK_COMMAND was not supplied"
fi

# Do not use jq: this runs on minimal VPS/CI images. Values are controlled by
# the operator/commands above and JSON-escaped by a tiny PHP-free routine.
json_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g; s/	/\\t/g; s/\r/\\r/g; s/\n/\\n/g'; }
{
    printf '{"harness":"release-evidence","timestamp":"%s","status":"%s","scratch_db":"%s","results":[' "$RUN_TS" "$STATUS" "$(json_escape "$SCRATCH_DB")"
    first=1
    for item in "${RESULTS[@]}"; do
        IFS='|' read -r name state detail <<< "$item"
        [[ $first -eq 1 ]] || printf ','; first=0
        printf '{"name":"%s","status":"%s","detail":"%s"}' "$(json_escape "$name")" "$(json_escape "$state")" "$(json_escape "$detail")"
    done
    printf '],"external_proof_required":true,"note":"A pass proves only commands executed in this run; contract mode proves nothing about production."}\n'
} > "$REPORT"
echo "evidence report: $REPORT"
[[ "$STATUS" == pass ]] || exit 1
