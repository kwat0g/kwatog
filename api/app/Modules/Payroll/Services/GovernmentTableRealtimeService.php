<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Services\OutboxService;
use App\Modules\Payroll\Events\GovernmentContributionTableChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one content-free table refresh per database transaction. */
final class GovernmentTableRealtimeService
{
    public function stage(string $action, ?string $version = null): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new GovernmentContributionTableChanged(
                action: $action,
                version: $version ?? now()->format('Y-m-d\TH:i:s.uP'),
            ),
            'government-table-refresh:'.$transactionId,
        );
    }
}
