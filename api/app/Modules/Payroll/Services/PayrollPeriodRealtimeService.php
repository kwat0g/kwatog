<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Services\OutboxService;
use App\Modules\Payroll\Events\PayrollPeriodChanged;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, transaction-coalesced publisher for the payroll-period aggregate. */
final class PayrollPeriodRealtimeService
{
    public function stage(PayrollPeriod $period, string $action, ?string $version = null): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new PayrollPeriodChanged(
            hashId: (string) $period->hash_id,
            action: $action,
            version: $version ?? $period->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
            actorId: auth()->user()?->hash_id,
            timestamp: now()->toISOString(),
        ), "payroll-period-refresh:{$period->getKey()}:{$transactionId}");
    }
}
