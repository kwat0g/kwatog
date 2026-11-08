<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Events\ChainListenerRunsChanged;
use App\Common\Models\ChainListenerRun;
use App\Modules\Dashboard\Observers\DashboardCacheInvalidationObserver;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Stages content-free listener-run refreshes for authorized recovery viewers. */
final class ChainListenerRunRealtimeService
{
    public function stage(ChainListenerRun $run, string $action): void
    {
        // Queue lifecycle rows are commonly changed with query-builder writes,
        // so the normal Eloquent dashboard invalidation observer cannot see
        // them. Retire the cached backlog before staging the refresh signal;
        // after commit, dashboard readers must not refill from the old count.
        try {
            app(DashboardCacheInvalidationObserver::class)->invalidateSource($run);
        } catch (Throwable $exception) {
            Log::warning('Chain listener dashboard cache invalidation could not be staged.', [
                'exception' => $exception::class,
            ]);
        }

        $runId = (string) $run->getKey();
        $entityHashId = hash_hmac('sha256', $runId, (string) config('app.key'));
        $actor = auth()->user();
        $actorHashId = $actor instanceof User && is_string($actor->hash_id) ? $actor->hash_id : null;
        $signal = new ChainListenerRunsChanged(
            entityHashId: $entityHashId,
            action: $action,
            version: $run->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: $actorHashId,
        );

        try {
            if (! Schema::hasTable('event_outbox')) {
                Log::warning('Chain-listener realtime outbox is unavailable; using queued broadcast fallback.', [
                    'event_id' => $signal->eventId,
                ]);
                $this->dispatchBestEffort($signal);

                return;
            }

            $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
                ? (string) DB::scalar('select txid_current()')
                : (string) Str::uuid();

            app(OutboxService::class)->recordCoalesced(
                $signal,
                "chain-listener-run-refresh:{$entityHashId}:{$transactionId}",
            );
        } catch (Throwable $exception) {
            Log::critical('Chain listener run changed but its durable realtime outbox write failed.', [
                'event_id' => $signal->eventId,
                'error' => $exception->getMessage(),
            ]);
            $this->dispatchBestEffort($signal);
        }
    }

    /** Queue lifecycle writers use query-builder updates and resolve the row by job correlation. */
    public function stageForJob(string $outboxId, string $jobUuid, string $action): void
    {
        $run = ChainListenerRun::query()
            ->where('outbox_id', $outboxId)
            ->where('job_uuid', $jobUuid)
            ->first();

        if ($run !== null) {
            $this->stage($run, $action);
        }
    }

    private function dispatchBestEffort(ChainListenerRunsChanged $signal): void
    {
        try {
            event($signal);
        } catch (Throwable $exception) {
            Log::critical('Chain listener realtime fallback could not be queued.', [
                'event_id' => $signal->eventId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
