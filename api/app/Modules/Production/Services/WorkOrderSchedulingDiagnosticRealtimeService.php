<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\WorkOrderSchedulingDiagnosticChanged;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces scheduler-diagnostic writes into one after-commit work-order ping. */
final class WorkOrderSchedulingDiagnosticRealtimeService
{
    public function stage(WorkOrder $workOrder): void
    {
        $usesPostgresTransactionId = DB::transactionLevel() > 0
            && DB::connection()->getDriverName() === 'pgsql';
        $transactionId = $usesPostgresTransactionId
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $version = $workOrder->fresh()?->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? $workOrder->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(new WorkOrderSchedulingDiagnosticChanged(
            workOrderHashId: (string) $workOrder->hash_id,
            action: 'updated',
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "work-order-scheduling-diagnostic-refresh:{$workOrder->getKey()}:{$transactionId}");
    }
}
