<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Events\MrpRunChanged;
use App\Modules\MRP\Models\MrpRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one durable run refresh per row and transaction. */
final class MrpRunRealtimeService
{
    public function stage(MrpRun $run, string $action): void
    {
        $version = $run->fresh()?->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? $run->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        if (! $actor instanceof User && $run->triggered_by_user_id !== null) {
            $actor = User::query()->find($run->triggered_by_user_id);
        }

        app(OutboxService::class)->recordCoalesced(new MrpRunChanged(
            runHashId: (string) $run->hash_id,
            action: $action,
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "mrp-run-refresh:{$run->getKey()}:{$transactionId}");
    }
}
