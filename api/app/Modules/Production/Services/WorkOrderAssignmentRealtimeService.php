<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\WorkOrderAssignmentChanged;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one durable staffing refresh per work order and database transaction. */
final class WorkOrderAssignmentRealtimeService
{
    /** @param list<int> $additionalEmployeeIds */
    public function stage(WorkOrder $workOrder, string $action, array $additionalEmployeeIds = []): void
    {
        // Crew membership is part of the work-order aggregate. Touching the
        // parent gives the refresh a version even for rework crews, where the
        // assignment service does not otherwise update the parent row.
        $workOrder->touch();
        $version = $workOrder->fresh()?->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $recipients = app(AssignedWorkOrderAudience::class)->userHashIdsFor($workOrder, $additionalEmployeeIds);

        app(OutboxService::class)->recordCoalesced(new WorkOrderAssignmentChanged(
            workOrderHashId: (string) $workOrder->hash_id,
            action: $action,
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
            recipientUserHashIds: $recipients,
        ), "work-order-assignment-refresh:{$workOrder->getKey()}:{$transactionId}");
    }
}
