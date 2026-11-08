<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Services\OutboxService;
use App\Modules\Attendance\Events\ShiftChanged;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces shift refresh pings by record and database transaction. */
final class ShiftRealtimeService
{
    public function stage(Shift $shift, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new ShiftChanged(
                shiftHashId: (string) $shift->hash_id,
                action: $action,
                version: $shift->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
                actorUserHashId: Auth::user()?->hash_id,
            ),
            'shift-refresh:'.$shift->getKey().':'.$transactionId,
        );

        if (in_array($action, ['deleted', 'restored'], true)
            || ($action === 'updated' && $shift->wasChanged(['start_time', 'end_time', 'is_active']))) {
            app(AssignedWorkOrderRefreshService::class)->stageForShift((int) $shift->getKey());
        }
    }
}
