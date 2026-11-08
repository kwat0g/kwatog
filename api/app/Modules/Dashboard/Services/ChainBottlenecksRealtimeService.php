<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\OutboxService;
use App\Modules\Dashboard\Events\ChainBottlenecksChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces bottleneck source writes into one private ping per transaction. */
final class ChainBottlenecksRealtimeService
{
    public function stage(): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new ChainBottlenecksChanged,
            "chain-bottlenecks-refresh:{$transactionId}",
        );
    }
}
