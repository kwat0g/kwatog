<?php

declare(strict_types=1);

namespace App\Modules\DSS\Services;

use App\Common\Services\OutboxService;
use App\Modules\DSS\Events\DssChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One coalesced, durable refresh signal per DSS write transaction. */
final class DssRealtimeService
{
    public function stage(string $action, ?string $version = null): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new DssChanged(
                action: $action,
                version: $version ?? now()->format('Y-m-d\TH:i:s.uP'),
            ),
            'dss-refresh:'.$transactionId,
        );
    }
}
