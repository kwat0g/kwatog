<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\WorkOrderOperationChanged;
use App\Modules\Production\Models\WoOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages a single operation refresh per work order and database transaction. */
final class WorkOrderOperationRealtimeService
{
    public function stage(WoOperation $operation, string $action): void
    {
        $workOrder = $operation->workOrder()->first();
        if (! $workOrder) {
            return;
        }

        // Operation rows are part of the work-order aggregate. Touch the parent
        // so the aggregate version changes even when only a child row was saved.
        $workOrder->touch();
        $version = $workOrder->fresh()?->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(new WorkOrderOperationChanged(
            workOrderHashId: (string) $workOrder->hash_id,
            operationHashId: (string) $operation->hash_id,
            action: $action,
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "work-order-operation-refresh:{$workOrder->getKey()}:{$transactionId}");
    }
}
