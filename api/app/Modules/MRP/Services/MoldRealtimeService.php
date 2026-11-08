<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Events\MoldChanged;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use App\Modules\Production\Services\ProductionDashboardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one durable mold refresh per record and database transaction. */
final class MoldRealtimeService
{
    public function stage(Mold $mold, string $action): void
    {
        // Molds feed the cached Production dashboard and scheduler projections.
        ProductionDashboardService::forgetCache();

        if (in_array($action, ['created', 'deleted', 'restored'], true) || array_intersect(array_keys($mold->getChanges()), [
            'mold_code', 'name', 'product_id', 'status', 'current_work_order_id', 'current_rework_ncr_id',
            'max_shots_before_maintenance', 'lifetime_max_shots', 'deleted_at',
        ]) !== []) {
            app(AssignedWorkOrderRefreshService::class)->stageForMold((int) $mold->getKey());
        }

        $version = $mold->fresh()?->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? $mold->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(new MoldChanged(
            moldHashId: (string) $mold->hash_id,
            action: $action,
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "mold-refresh:{$mold->getKey()}:{$transactionId}");
    }
}
