<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\OutboxService;
use App\Modules\Dashboard\Events\HrDashboardChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces HR dashboard refresh pings within each database transaction. */
final class HrDashboardRealtimeService
{
    public function stage(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new HrDashboardChanged,
            "hr-dashboard-refresh:{$transactionId}",
        );
    }
}
