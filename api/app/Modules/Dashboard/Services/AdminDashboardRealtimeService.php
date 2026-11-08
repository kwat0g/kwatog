<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\OutboxService;
use App\Modules\Dashboard\Events\AdminDashboardChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces system-dashboard refresh pings within each PostgreSQL transaction. */
final class AdminDashboardRealtimeService
{
    public function stage(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new AdminDashboardChanged,
            "admin-dashboard-refresh:{$transactionId}",
        );
    }
}
