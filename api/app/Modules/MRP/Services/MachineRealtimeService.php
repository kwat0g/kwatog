<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Events\MachineChanged;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use App\Modules\Production\Services\ProductionDashboardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one durable machine refresh per record and database transaction. */
final class MachineRealtimeService
{
    public function stage(Machine $machine, string $action): void
    {
        // Machine status/ownership and machine master data feed the cached
        // Production dashboard. Centralize its post-commit eviction here so
        // direct Eloquent writers receive the same cache freshness as the
        // MachineService transition endpoint.
        ProductionDashboardService::forgetCache();

        if (in_array($action, ['created', 'deleted', 'restored'], true) || array_intersect(array_keys($machine->getChanges()), [
            'machine_code', 'name', 'status', 'current_work_order_id', 'current_rework_ncr_id', 'deleted_at',
        ]) !== []) {
            app(AssignedWorkOrderRefreshService::class)->stageForMachine((int) $machine->getKey());
        }

        $version = $machine->fresh()?->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? $machine->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(new MachineChanged(
            machineHashId: (string) $machine->hash_id,
            action: $action,
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "machine-refresh:{$machine->getKey()}:{$transactionId}");
    }
}
