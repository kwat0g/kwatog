<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Services\OutboxService;
use App\Modules\Payroll\Events\DeMinimisBenefitChanged;
use App\Modules\Payroll\Models\DeMinimisBenefit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, transaction-coalesced refresh signal for de minimis ledger writes. */
final class DeMinimisRealtimeService
{
    public function stage(DeMinimisBenefit $benefit, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new DeMinimisBenefitChanged(
            hashId: (string) $benefit->hash_id,
            action: $action,
            version: $benefit->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            timestamp: now()->toISOString(),
        ), "payroll-de-minimis-refresh:{$transactionId}");
    }
}
