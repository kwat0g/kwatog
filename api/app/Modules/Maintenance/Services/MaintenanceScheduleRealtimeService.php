<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Events\MaintenanceScheduleChanged;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, transaction-coalesced publisher for the maintenance-schedule aggregate. */
final class MaintenanceScheduleRealtimeService
{
    public function stage(MaintenanceSchedule $schedule, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(new MaintenanceScheduleChanged(
            hashId: (string) $schedule->hash_id,
            action: $action,
            version: $schedule->fresh()?->updated_at?->format('Y-m-d\\TH:i:s.uP')
                ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "maintenance-schedule-refresh:{$schedule->getKey()}:{$transactionId}");
    }
}
