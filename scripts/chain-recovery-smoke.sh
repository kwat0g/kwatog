#!/usr/bin/env bash

set -euo pipefail

# Disposable, local/staging-only proof that the forward migration chain and a
# real Redis worker can execute the narrow listener-replay contract. The
# database name is deliberately constrained so cleanup cannot target an
# existing application database by accident.

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# Include the shell PID so two operators/CI jobs started in the same second
# cannot collide on the disposable database or Redis namespace.
SMOKE_DB="${SMOKE_DB:-ogami_chain_smoke_$(date -u +%Y%m%d%H%M%S)_$$}"
SMOKE_PREFIX="${SMOKE_PREFIX:-ogami_chain_smoke_${SMOKE_DB}_}"
SMOKE_QUEUE="${SMOKE_QUEUE:-chain_worker_smoke}"
DB_USERNAME="${DB_USERNAME:-ogami}"
DB_PASSWORD="${DB_PASSWORD:-ogami_dev_pw}"
SMOKE_LOG="$(mktemp /tmp/ogami-chain-smoke.XXXXXX)"
SMOKE_DB_CREATED=0

case "$SMOKE_DB" in
    ogami_chain_smoke_[a-z0-9_]*) ;;
    *) echo "SMOKE_DB must start with ogami_chain_smoke_ and contain only lowercase letters, digits, and underscores." >&2; exit 2 ;;
esac

case "$SMOKE_PREFIX" in
    ogami_chain_smoke_${SMOKE_DB}_*) ;;
    *) echo "SMOKE_PREFIX must be the unique prefix derived from SMOKE_DB; refusing to delete an arbitrary Redis namespace." >&2; exit 2 ;;
esac

compose() {
    docker compose "$@"
}

api_artisan() {
    compose exec -T \
        -e APP_ENV=testing \
        -e DB_DATABASE="$SMOKE_DB" \
        -e DB_HOST=db \
        -e DB_PORT=5432 \
        -e DB_USERNAME="$DB_USERNAME" \
        -e DB_PASSWORD="$DB_PASSWORD" \
        -e CACHE_STORE=array \
        -e SESSION_DRIVER=array \
        -e QUEUE_CONNECTION=redis \
        -e REDIS_PREFIX="$SMOKE_PREFIX" \
        -e REDIS_QUEUE="$SMOKE_QUEUE" \
        api php artisan "$@"
}

cleanup() {
    local original_status=$?
    local cleanup_status=0
    local keys=""
    local residual_keys=""
    local db_exists=""

    if [[ "$SMOKE_DB_CREATED" -eq 1 ]]; then
        if ! keys="$(compose exec -T redis redis-cli --raw --scan --pattern "${SMOKE_PREFIX}*")"; then
            echo "ERROR: could not inspect disposable Redis keys during cleanup" >&2
            cleanup_status=1
        elif [[ -n "$keys" ]]; then
            while IFS= read -r key; do
                [[ -z "$key" ]] && continue
                if ! compose exec -T redis redis-cli DEL "$key" >/dev/null; then
                    echo "ERROR: could not remove disposable Redis key: $key" >&2
                    cleanup_status=1
                fi
            done <<< "$keys"
        fi

        if ! compose exec -T db psql -U "$DB_USERNAME" -d postgres -v ON_ERROR_STOP=1 \
            -c "DROP DATABASE IF EXISTS \"$SMOKE_DB\"" >/dev/null; then
            echo "ERROR: could not drop disposable database: $SMOKE_DB" >&2
                cleanup_status=1
        fi

        if ! db_exists="$(compose exec -T db psql -U "$DB_USERNAME" -d postgres -Atqc \
            "SELECT 1 FROM pg_database WHERE datname = '$SMOKE_DB'")"; then
            echo "ERROR: could not verify disposable database cleanup: $SMOKE_DB" >&2
            cleanup_status=1
        elif [[ "$db_exists" == "1" ]]; then
            echo "ERROR: disposable database still exists after cleanup: $SMOKE_DB" >&2
            cleanup_status=1
        fi

        if ! residual_keys="$(compose exec -T redis redis-cli --raw --scan --pattern "${SMOKE_PREFIX}*")"; then
            echo "ERROR: could not verify disposable Redis cleanup" >&2
            cleanup_status=1
        elif [[ -n "$residual_keys" ]]; then
            echo "ERROR: disposable Redis keys remain after cleanup: $residual_keys" >&2
            cleanup_status=1
        fi
    fi

    if ! rm -f "$SMOKE_LOG"; then
        echo "ERROR: could not remove temporary smoke log: $SMOKE_LOG" >&2
        cleanup_status=1
    fi

    if [[ "$cleanup_status" -ne 0 ]]; then
        echo "ERROR: chain smoke cleanup did not complete; inspect disposable state before retrying." >&2
        original_status=1
    fi
    exit "$original_status"
}
trap cleanup EXIT

if compose exec -T db psql -U "$DB_USERNAME" -d postgres -Atqc \
    "SELECT 1 FROM pg_database WHERE datname = '$SMOKE_DB'" | grep -q '^1$'; then
    echo "Refusing to reuse existing disposable database: $SMOKE_DB" >&2
    exit 2
fi

compose exec -T db psql -U "$DB_USERNAME" -d postgres -v ON_ERROR_STOP=1 \
    -c "CREATE DATABASE \"$SMOKE_DB\"" >/dev/null
SMOKE_DB_CREATED=1

echo "Applying the complete migration chain to $SMOKE_DB..."
api_artisan migrate --env=testing --force --no-interaction >"$SMOKE_LOG"
tail -n 5 "$SMOKE_LOG"

read -r -d '' CREATE_CODE <<'PHP' || true
$pr = \App\Modules\Purchasing\Models\PurchaseRequest::factory()->create(['department_id' => null]);
$now = now();
\Illuminate\Support\Facades\DB::table('purchase_requests')->where('id', $pr->id)->update(['status' => 'approved', 'approved_at' => $now, 'updated_at' => $now]);
$pr = $pr->fresh();
$event = new \App\Modules\Purchasing\Events\PurchaseRequestApproved($pr);
$encoded = app(\App\Common\Services\OutboxEventCodec::class)->encode($event);
$outboxId = (string) \Illuminate\Support\Str::uuid();
$sourceRunId = (string) \Illuminate\Support\Str::uuid();
$jobUuid = (string) \Illuminate\Support\Str::uuid();
\Illuminate\Support\Facades\DB::table('event_outbox')->insert(['id' => $outboxId, 'event_type' => $encoded['event_type'], 'payload' => json_encode($encoded['payload'], JSON_THROW_ON_ERROR), 'dedupe_key' => 'worker-smoke-'.$sourceRunId, 'status' => 'published', 'attempts' => 1, 'available_at' => $now, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
\Illuminate\Support\Facades\DB::table('chain_step_runs')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'outbox_id' => $outboxId, 'chain' => 'p2p', 'entity_type' => 'purchase_request', 'entity_id' => $pr->id, 'entity_hash_id' => $pr->hash_id, 'step' => 'approved', 'event_type' => $encoded['event_type'], 'event_key' => 'worker-smoke-step-'.$sourceRunId, 'status' => 'published', 'attempts' => 1, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
\Illuminate\Support\Facades\DB::table('chain_listener_runs')->insert(['id' => $sourceRunId, 'outbox_id' => $outboxId, 'job_uuid' => $jobUuid, 'event_type' => $encoded['event_type'], 'listener_class' => \App\Modules\Purchasing\Listeners\ConsolidatePurchaseOrders::class, 'listener_method' => 'handle', 'status' => 'failed', 'attempts' => 3, 'started_at' => $now, 'last_attempt_at' => $now, 'failed_at' => $now, 'last_error' => 'worker smoke source', 'outcome_status' => 'failed', 'outcome_code' => 'worker_smoke_source', 'outcome_message' => 'worker smoke source', 'outcome_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
\App\Common\Support\OutboxDispatchContext::run($outboxId, $encoded['event_type'], function () use ($event, $sourceRunId): void { app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch(new \App\Common\Jobs\ReplayChainListenerJob(\App\Modules\Purchasing\Listeners\ConsolidatePurchaseOrders::class, 'handle', $event)); }, $sourceRunId);
echo "SOURCE_RUN=".$sourceRunId."\n";
PHP

CREATE_OUTPUT="$(api_artisan tinker --env=testing --execute="$CREATE_CODE")"
SOURCE_RUN="$(printf '%s\n' "$CREATE_OUTPUT" | grep -Eo 'SOURCE_RUN=[0-9a-f-]{36}' | tail -n 1 | cut -d= -f2 || true)"
if [[ ! "$SOURCE_RUN" =~ ^[0-9a-f-]{36}$ ]]; then
    echo "Could not capture the replay source run. Tinker output was:" >&2
    printf '%s\n' "$CREATE_OUTPUT" >&2
    exit 1
fi

echo "Running a real Redis worker for queue [$SMOKE_QUEUE]..."
api_artisan queue:work redis --queue="$SMOKE_QUEUE" --stop-when-empty --tries=1 --timeout=120 --sleep=1 --no-interaction

read -r -d '' VERIFY_CODE <<'PHP' || true
$source = (string) env('SMOKE_SOURCE_RUN');
$run = \Illuminate\Support\Facades\DB::table('chain_listener_runs')->where('replayed_from_id', $source)->first(['status', 'outcome_status', 'outcome_code', 'replayed_from_id']);
$outbox = \Illuminate\Support\Facades\DB::table('event_outbox')->where('status', 'published')->count();
$failed = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
echo json_encode(['replay' => $run, 'published_outbox' => $outbox, 'failed_jobs' => $failed], JSON_THROW_ON_ERROR)."\n";
PHP

VERIFY_OUTPUT="$(compose exec -T \
    -e APP_ENV=testing \
    -e DB_DATABASE="$SMOKE_DB" \
    -e DB_HOST=db \
    -e DB_PORT=5432 \
    -e DB_USERNAME="$DB_USERNAME" \
    -e DB_PASSWORD="$DB_PASSWORD" \
    -e CACHE_STORE=array \
    -e SESSION_DRIVER=array \
    -e QUEUE_CONNECTION=redis \
    -e REDIS_PREFIX="$SMOKE_PREFIX" \
    -e REDIS_QUEUE="$SMOKE_QUEUE" \
    -e SMOKE_SOURCE_RUN="$SOURCE_RUN" \
    api php artisan tinker --env=testing --execute="$VERIFY_CODE")"
echo "$VERIFY_OUTPUT"

grep -Fq '"status":"completed"' <<< "$VERIFY_OUTPUT"
grep -Fq '"outcome_status":"skipped"' <<< "$VERIFY_OUTPUT"
grep -Fq '"outcome_code":"purchase_request_has_no_lines"' <<< "$VERIFY_OUTPUT"
grep -Fq "\"replayed_from_id\":\"$SOURCE_RUN\"" <<< "$VERIFY_OUTPUT"
grep -Fq '"failed_jobs":0' <<< "$VERIFY_OUTPUT"

echo "Migration + real-worker chain replay smoke passed; disposable state will be removed."
