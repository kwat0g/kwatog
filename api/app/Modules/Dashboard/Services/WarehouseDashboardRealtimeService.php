<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\OutboxService;
use App\Modules\Dashboard\Events\WarehouseDashboardChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces all source writes into one private dashboard ping per transaction. */
final class WarehouseDashboardRealtimeService
{
    public function stage(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new WarehouseDashboardChanged(
            eventId: (string) Str::uuid(),
            timestamp: now()->toISOString(),
        ), "warehouse-dashboard-refresh:{$transactionId}");
    }
}
