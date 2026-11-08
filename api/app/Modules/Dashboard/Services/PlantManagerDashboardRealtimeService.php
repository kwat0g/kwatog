<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\OutboxService;
use App\Modules\Dashboard\Events\PlantManagerDashboardChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces dashboard source writes into one private ping per transaction. */
final class PlantManagerDashboardRealtimeService
{
    public function stage(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new PlantManagerDashboardChanged(
            eventId: (string) Str::uuid(),
            timestamp: now()->toISOString(),
        ), "plant-manager-dashboard-refresh:{$transactionId}");
    }
}
