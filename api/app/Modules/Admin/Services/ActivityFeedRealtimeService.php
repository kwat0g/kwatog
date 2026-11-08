<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Services\OutboxService;
use App\Modules\Admin\Events\ActivityFeedChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces activity-feed refresh pings within each PostgreSQL transaction. */
final class ActivityFeedRealtimeService
{
    public function stage(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new ActivityFeedChanged,
            "admin-activity-refresh:{$transactionId}",
        );
    }
}
