<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\ProductionScheduleChanged;
use App\Modules\Production\Models\ProductionSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persists a transaction-coalesced refresh for every schedule lifecycle write. */
final class ProductionScheduleRealtimeService
{
    public function stage(ProductionSchedule $schedule, string $action): void
    {
        $workOrder = $schedule->workOrder()->withTrashed()->first();
        if (! $workOrder) {
            return;
        }

        $version = $schedule->fresh()?->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? $schedule->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(new ProductionScheduleChanged(
            scheduleHashId: (string) $schedule->hash_id,
            workOrderHashId: (string) $workOrder->hash_id,
            action: $action,
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
            recipientUserHashIds: app(AssignedWorkOrderAudience::class)->userHashIdsFor($workOrder),
        ), "production-schedule-refresh:{$schedule->getKey()}:{$transactionId}");

        // A capacity reservation can change another assigned WO's start blockers,
        // so refresh both the current and previous equipment assignments.
        $availabilityFields = [
            'work_order_id', 'machine_id', 'mold_id', 'scheduled_start', 'scheduled_end',
            'status', 'is_confirmed', 'reserves_capacity',
        ];
        if (in_array($action, ['created', 'deleted'], true)
            || array_intersect(array_keys($schedule->getChanges()), $availabilityFields) !== []) {
            $previous = $schedule->getPrevious();
            $readiness = app(AssignedWorkOrderRefreshService::class);

            $machineIds = collect([$schedule->machine_id, $previous['machine_id'] ?? null])
                ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
                ->map(fn ($id): int => (int) $id)
                ->unique();
            foreach ($machineIds as $machineId) {
                $readiness->stageForMachine($machineId);
            }

            $moldIds = collect([$schedule->mold_id, $previous['mold_id'] ?? null])
                ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
                ->map(fn ($id): int => (int) $id)
                ->unique();
            foreach ($moldIds as $moldId) {
                $readiness->stageForMold($moldId);
            }
        }
    }
}
