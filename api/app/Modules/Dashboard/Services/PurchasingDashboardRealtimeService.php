<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\OutboxService;
use App\Modules\Dashboard\Events\PurchasingDashboardChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces authorized dashboard refresh pings within one database transaction. */
final class PurchasingDashboardRealtimeService
{
    public function stage(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new PurchasingDashboardChanged,
            "purchasing-dashboard-refresh:{$transactionId}",
        );
    }
}
