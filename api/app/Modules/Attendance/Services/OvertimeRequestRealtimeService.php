<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Services\OutboxService;
use App\Modules\Attendance\Events\OvertimeRequestChanged;
use App\Modules\Attendance\Models\OvertimeRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one coalesced durable refresh ping per overtime row and transaction. */
final class OvertimeRequestRealtimeService
{
    public function stage(OvertimeRequest $request, string $action, ?string $transactionId = null): void
    {
        $transactionId ??= $this->transactionToken();
        $request->loadMissing('employee.user');

        app(OutboxService::class)->recordCoalesced(
            new OvertimeRequestChanged(
                overtimeRequestHashId: (string) $request->hash_id,
                ownerUserHashId: $request->employee?->user?->hash_id,
                action: $action,
                version: (string) $request->version,
                actorUserHashId: Auth::user()?->hash_id,
                occurredAt: now()->toIso8601String(),
            ),
            'overtime-request-refresh:'.(string) $request->getKey().':'.$transactionId,
        );
    }

    private function transactionToken(): string
    {
        return DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
    }
}
