#!/usr/bin/env bash

set -euo pipefail

# Disposable local/staging-only proof that a real Redis worker can be killed
# during a job and that Redis/Laravel redelivers the reserved job after the
# configured retry lease. This intentionally uses no application database.

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

SMOKE_ID="${SMOKE_ID:-$(date -u +%Y%m%d%H%M%S)_$$}"
SMOKE_PREFIX="${SMOKE_PREFIX:-ogami_worker_recovery_smoke_${SMOKE_ID}_}"
SMOKE_QUEUE="${SMOKE_QUEUE:-worker_recovery_smoke_${SMOKE_ID}}"
PROBE_KEY="${PROBE_KEY:-worker_recovery_probe}"
RETRY_AFTER_SECONDS="${RETRY_AFTER_SECONDS:-3}"
FIRST_ATTEMPT_SLEEP_SECONDS="${FIRST_ATTEMPT_SLEEP_SECONDS:-8}"
WORKER_TIMEOUT_SECONDS="${WORKER_TIMEOUT_SECONDS:-20}"
WORKER_PID_FILE="/tmp/${PROBE_KEY}_${SMOKE_ID}.pid"
WORKER_LOG="$(mktemp /tmp/ogami-worker-recovery.XXXXXX)"
WORKER_HOST_PID=""
WORKER_CONTAINER_PID=""

case "$SMOKE_PREFIX" in
    ogami_worker_recovery_smoke_[a-z0-9_]*) ;;
    *) echo "SMOKE_PREFIX must start with ogami_worker_recovery_smoke_ and contain only lowercase letters, digits, and underscores." >&2; exit 2 ;;
esac

case "$SMOKE_QUEUE" in
    worker_recovery_smoke_[a-z0-9_]*) ;;
    *) echo "SMOKE_QUEUE must start with worker_recovery_smoke_ and contain only lowercase letters, digits, and underscores." >&2; exit 2 ;;
esac

case "$PROBE_KEY" in
    worker_recovery_probe) ;;
    *) echo "PROBE_KEY is fixed to the safe diagnostic key." >&2; exit 2 ;;
esac

if ! [[ "$RETRY_AFTER_SECONDS" =~ ^[1-9][0-9]*$ ]] || ! [[ "$FIRST_ATTEMPT_SLEEP_SECONDS" =~ ^[1-9][0-9]*$ ]] || ! [[ "$WORKER_TIMEOUT_SECONDS" =~ ^[1-9][0-9]*$ ]]; then
    echo "Retry, sleep, and timeout values must be positive integers." >&2
    exit 2
fi

compose() {
    docker compose "$@"
}

api_exec() {
    compose exec -T \
        -e APP_ENV=testing \
        -e APP_DEBUG=false \
        -e CACHE_STORE=array \
        -e SESSION_DRIVER=array \
        -e QUEUE_CONNECTION=redis \
        -e QUEUE_FAILED_DRIVER=database-uuids \
        -e REDIS_PREFIX="$SMOKE_PREFIX" \
        -e REDIS_QUEUE="$SMOKE_QUEUE" \
        -e REDIS_QUEUE_RETRY_AFTER="$RETRY_AFTER_SECONDS" \
        api "$@"
}

redis_key() {
    # Do not let redis-cli consume the caller's stdin. Cleanup iterates over
    # newline-delimited keys, so inheriting that stream would delete only the
    # first key and leave the rest of the disposable namespace behind.
    compose exec -T redis redis-cli --raw "$@" </dev/null
}

cleanup() {
    local original_status=$?
    local cleanup_status=0
    local keys=""

    if [[ -n "$WORKER_HOST_PID" ]] && kill -0 "$WORKER_HOST_PID" 2>/dev/null; then
        kill -KILL "$WORKER_HOST_PID" 2>/dev/null || true
        wait "$WORKER_HOST_PID" 2>/dev/null || true
    fi

    if [[ -n "$WORKER_CONTAINER_PID" ]]; then
        compose exec -T api sh -c "kill -KILL '$WORKER_CONTAINER_PID' 2>/dev/null || true" >/dev/null 2>&1 || true
    fi

    compose exec -T api sh -c "rm -f '$WORKER_PID_FILE'" >/dev/null 2>&1 || true

    if ! keys="$(redis_key --scan --pattern "${SMOKE_PREFIX}*")"; then
        echo "ERROR: could not inspect disposable Redis keys during cleanup." >&2
        cleanup_status=1
    elif [[ -n "$keys" ]]; then
        while IFS= read -r key; do
            [[ -z "$key" ]] && continue
            if ! redis_key DEL "$key" >/dev/null; then
                echo "ERROR: could not remove disposable Redis key: $key" >&2
                cleanup_status=1
            fi
        done <<< "$keys"
    fi

    if ! rm -f "$WORKER_LOG"; then
        echo "ERROR: could not remove temporary worker log: $WORKER_LOG" >&2
        cleanup_status=1
    fi

    if [[ "$cleanup_status" -ne 0 ]]; then
        echo "ERROR: worker recovery smoke cleanup did not complete; inspect the constrained Redis namespace before retrying." >&2
        original_status=1
    fi

    exit "$original_status"
}
trap cleanup EXIT

echo "Checking the test-only worker probe is available..."
api_exec php -r 'require "vendor/autoload.php"; exit(class_exists("Tests\\Support\\Queue\\WorkerRecoveryProbeJob") ? 0 : 1);'

echo "Clearing any pre-existing key in the constrained namespace..."
keys="$(redis_key --scan --pattern "${SMOKE_PREFIX}*")"
if [[ -n "$keys" ]]; then
    while IFS= read -r key; do
        [[ -z "$key" ]] && continue
        redis_key DEL "$key" >/dev/null
    done <<< "$keys"
fi

DISPATCH_CODE="\$job = new \\Tests\\Support\\Queue\\WorkerRecoveryProbeJob('${PROBE_KEY}', ${FIRST_ATTEMPT_SLEEP_SECONDS}); \\Illuminate\\Support\\Facades\\Queue::connection('redis')->pushOn('${SMOKE_QUEUE}', \$job); echo 'queued';"
DISPATCH_OUTPUT="$(api_exec php artisan tinker --env=testing --execute="$DISPATCH_CODE")"
grep -Fq 'queued' <<< "$DISPATCH_OUTPUT"

echo "Starting a real worker on queue [$SMOKE_QUEUE]..."
compose exec -T \
    -e APP_ENV=testing \
    -e APP_DEBUG=false \
    -e CACHE_STORE=array \
    -e SESSION_DRIVER=array \
    -e QUEUE_CONNECTION=redis \
    -e QUEUE_FAILED_DRIVER=database-uuids \
    -e REDIS_PREFIX="$SMOKE_PREFIX" \
    -e REDIS_QUEUE="$SMOKE_QUEUE" \
    -e REDIS_QUEUE_RETRY_AFTER="$RETRY_AFTER_SECONDS" \
    api sh -c "echo \$\$ > '$WORKER_PID_FILE'; exec php artisan queue:work redis --queue='$SMOKE_QUEUE' --tries=2 --timeout='$WORKER_TIMEOUT_SECONDS' --sleep=1 --no-interaction" >"$WORKER_LOG" 2>&1 &
WORKER_HOST_PID=$!

started=""
for _ in $(seq 1 30); do
    started="$(redis_key GET "${SMOKE_PREFIX}${PROBE_KEY}:started:1" || true)"
    [[ -n "$started" ]] && break
    sleep 0.5
done

if [[ -z "$started" ]]; then
    echo "ERROR: worker never entered attempt one." >&2
    sed -n '1,160p' "$WORKER_LOG" >&2 || true
    exit 1
fi

WORKER_CONTAINER_PID="$(compose exec -T api sh -c "cat '$WORKER_PID_FILE'" | tr -d '\\r\\n')"
if ! [[ "$WORKER_CONTAINER_PID" =~ ^[1-9][0-9]*$ ]]; then
    echo "ERROR: could not identify the worker PID inside the API container." >&2
    sed -n '1,160p' "$WORKER_LOG" >&2 || true
    exit 1
fi

echo "Killing worker PID $WORKER_CONTAINER_PID during attempt one..."
compose exec -T api sh -c "kill -KILL '$WORKER_CONTAINER_PID'"
WORKER_CONTAINER_PID=""
wait "$WORKER_HOST_PID" 2>/dev/null || true
WORKER_HOST_PID=""

# The first attempt is reserved, not immediately visible. Start the next
# worker only after the bounded lease has expired so --stop-when-empty cannot
# exit before Redis moves the job back to the ready list.
sleep "$((RETRY_AFTER_SECONDS + 1))"

echo "Starting a second real worker to consume the reclaimed job..."
api_exec php artisan queue:work redis --queue="$SMOKE_QUEUE" --stop-when-empty --tries=2 --timeout="$WORKER_TIMEOUT_SECONDS" --sleep=1 --no-interaction >"$WORKER_LOG" 2>&1

completed=""
for _ in $(seq 1 20); do
    completed="$(redis_key GET "${SMOKE_PREFIX}${PROBE_KEY}:completed" || true)"
    [[ -n "$completed" ]] && break
    sleep 0.5
done

if [[ -z "$completed" ]]; then
    echo "ERROR: reclaimed job did not complete." >&2
    sed -n '1,160p' "$WORKER_LOG" >&2 || true
    exit 1
fi

grep -Fq '"attempt":2' <<< "$completed"
started_count="$(redis_key --scan --pattern "${SMOKE_PREFIX}${PROBE_KEY}:started:*" | wc -l | tr -d ' ')"
[[ "$started_count" -eq 2 ]]

echo "Worker interruption/recovery smoke passed: attempt 1 was killed, Redis reclaimed the job, and attempt 2 completed exactly once."
