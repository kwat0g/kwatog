#!/usr/bin/env bash

set -euo pipefail

# Disposable proof that two independent Laravel workers share the Redis-backed
# MRP mutex. The holder uses the exact production lock name; the contender
# enters MrpEngineService, not a test double. No database rows are written when
# the mutex behaves correctly.

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

SMOKE_ID="${SMOKE_ID:-$(date -u +%Y%m%d%H%M%S)_$$}"
CACHE_PREFIX="${CACHE_PREFIX:-ogami_mrp_overlap_${SMOKE_ID}_}"
HOLDER_LOG="$(mktemp /tmp/ogami-mrp-overlap-holder.XXXXXX)"
CONTENDER_LOG="$(mktemp /tmp/ogami-mrp-overlap-contender.XXXXXX)"
HOLDER_PID=""

case "$CACHE_PREFIX" in
    ogami_mrp_overlap_[a-z0-9_]*) ;;
    *) echo "CACHE_PREFIX must start with ogami_mrp_overlap_ and contain only lowercase letters, digits, and underscores." >&2; exit 2 ;;
esac

compose() {
    docker compose "$@"
}

api_exec() {
    compose exec -T \
        -e APP_ENV=testing \
        -e APP_DEBUG=false \
        -e CACHE_STORE=redis \
        -e CACHE_PREFIX="$CACHE_PREFIX" \
        -e REDIS_CACHE_CONNECTION=cache \
        -e REDIS_CACHE_LOCK_CONNECTION=default \
        -e SESSION_DRIVER=array \
        api "$@"
}

cleanup() {
    local original_status=$?

    # The holder has a bounded sleep and releases its own lock in finally.
    # Waiting is safer than killing it and leaving the production lock key
    # leased until its TTL expires.
    if [[ -n "$HOLDER_PID" ]]; then
        wait "$HOLDER_PID" 2>/dev/null || true
    fi

    rm -f "$HOLDER_LOG" "$CONTENDER_LOG"
    exit "$original_status"
}
trap cleanup EXIT

redis_ping="$(compose exec -T redis redis-cli PING </dev/null | tr -d '\r')"
if [[ "$redis_ping" != PONG ]]; then
    echo "BLOCKED: Redis is unavailable; start the Redis service and rerun scripts/mrp-redis-overlap-smoke.sh." >&2
    exit 2
fi

read -r -d '' HOLDER_CODE <<'PHP' || true
$lock = \Illuminate\Support\Facades\Cache::lock('mrp:plant-run', 30);
if (! $lock->get()) {
    throw new \RuntimeException('MRP mutex was already held before the overlap probe started.');
}
echo "HOLDER_READY\n";
try {
    sleep(8);
} finally {
    $lock->release();
}
PHP

echo 'Starting worker 1 with the Redis-backed MRP lock...'
api_exec php artisan tinker --env=testing --execute="$HOLDER_CODE" >"$HOLDER_LOG" 2>&1 &
HOLDER_PID=$!

for _ in $(seq 1 20); do
    holder_output="$(<"$HOLDER_LOG")"
    [[ "$holder_output" == *HOLDER_READY* ]] && break
    sleep 0.25
done

holder_output="$(<"$HOLDER_LOG")"
if [[ "$holder_output" != *HOLDER_READY* ]]; then
    printf '%s\n' "$holder_output" >&2
    echo 'FAIL: worker 1 could not acquire the MRP Redis mutex.' >&2
    exit 1
fi

read -r -d '' CONTENDER_CODE <<'PHP' || true
try {
    app(\App\Modules\MRP\Services\MrpEngineService::class)
        ->runForActiveSalesOrders(\App\Modules\MRP\Enums\MrpRunTrigger::Scheduled);
    throw new \RuntimeException('MRP contender acquired a lock held by another worker.');
} catch (\App\Common\Exceptions\BusinessRuleException $e) {
    if ($e->getMessage() !== 'Another MRP run is already in progress. Retry after it finishes.') {
        throw $e;
    }
    echo "CONTENDER_BLOCKED\n";
}
PHP

echo 'Starting worker 2 through MrpEngineService...'
api_exec php artisan tinker --env=testing --execute="$CONTENDER_CODE" >"$CONTENDER_LOG" 2>&1
contender_output="$(<"$CONTENDER_LOG")"
if [[ "$contender_output" != *CONTENDER_BLOCKED* ]]; then
    printf '%s\n' "$contender_output" >&2
    echo 'FAIL: worker 2 was not blocked by the shared MRP mutex.' >&2
    exit 1
fi

wait "$HOLDER_PID"
HOLDER_PID=""

echo 'PASS: two independent Laravel workers shared Redis and the MRP contender was blocked.'
