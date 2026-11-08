<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\OutboxService;
use App\Modules\Dashboard\Events\PpcDashboardChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces PPC dashboard source writes into one private refresh per transaction. */
final class PpcDashboardRealtimeService
{
    public function stage(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new PpcDashboardChanged,
            "ppc-dashboard-refresh:{$transactionId}",
        );
    }
}
