<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Services\OutboxService;
use App\Modules\Attendance\Events\HolidayChanged;
use App\Modules\Attendance\Models\Holiday;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces holiday pings by record and database transaction. */
final class HolidayRealtimeService
{
    public function stage(Holiday $holiday, string $action): void
    {
        // Register cache retirement before OutboxService registers its
        // after-commit dispatch callback, so event-driven refetches cannot
        // observe a still-current generation.
        app(HolidayCacheGeneration::class)->invalidateAfterCommit((int) $holiday->date->year);
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        app(OutboxService::class)->recordCoalesced(
            new HolidayChanged(
                action: $action,
                version: $holiday->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            ),
            'holiday-refresh:'.$holiday->getKey().':'.$transactionId,
        );
    }
}
