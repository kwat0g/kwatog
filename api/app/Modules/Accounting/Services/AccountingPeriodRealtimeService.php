<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Events\AccountingPeriodChanged;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces period refresh signals per aggregate and database transaction. */
final class AccountingPeriodRealtimeService
{
    public function stage(AccountingPeriod $period, string $action): void
    {
        $actor = auth()->user();
        $actorId = $actor instanceof User && is_string($actor->hash_id) ? $actor->hash_id : null;
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $version = $period->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP');

        app(OutboxService::class)->recordCoalesced(new AccountingPeriodChanged(
            hashId: (string) $period->hash_id,
            action: $action,
            version: $version,
            actorId: $actorId,
        ), "accounting-period-refresh:{$period->getKey()}:{$transactionId}");
    }
}
