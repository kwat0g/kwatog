<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Common\Services\OutboxService;
use App\Modules\Leave\Events\LeaveTypeChanged;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one reference-data refresh per leave type and transaction. */
final class LeaveTypeRealtimeService
{
    public function stage(LeaveType $leaveType, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new LeaveTypeChanged(
                leaveTypeHashId: (string) $leaveType->hash_id,
                action: $action,
                version: $leaveType->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
            ),
            'leave-type-refresh:'.(string) $leaveType->getKey().':'.$transactionId,
        );
    }
}
