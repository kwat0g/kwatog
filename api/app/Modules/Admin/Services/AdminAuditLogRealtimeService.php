<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Services\OutboxService;
use App\Modules\Admin\Events\AdminAuditLogsChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces audit-log refresh pings within each database transaction. */
final class AdminAuditLogRealtimeService
{
    public function stage(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new AdminAuditLogsChanged,
            "admin-audit-logs-refresh:{$transactionId}",
        );
    }
}
