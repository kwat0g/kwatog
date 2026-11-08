<?php

declare(strict_types=1);

namespace App\Modules\Quality\Observers;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use App\Modules\Quality\Events\InspectionChanged;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one inspection refresh per persisted record and database transaction. */
final class InspectionRealtimeObserver
{
    public function created(Inspection $inspection): void
    {
        $this->stage($inspection, 'created');
    }

    public function updated(Inspection $inspection): void
    {
        $this->stage($inspection, 'updated');
    }

    public function deleted(Inspection $inspection): void
    {
        $this->stage($inspection, 'deleted');
    }

    private function stage(Inspection $inspection, string $action): void
    {
        $transaction = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $dedupeKey = "inspection-refresh:{$inspection->getKey()}:{$transaction}";

        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(new InspectionChanged(
            inspectionHashId: (string) $inspection->hash_id,
            action: $action,
            version: $inspection->updated_at?->toISOString() ?? now()->toISOString(),
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), $dedupeKey);

        app(AssignedWorkOrderRefreshService::class)->stageForInspection($inspection);
    }
}
