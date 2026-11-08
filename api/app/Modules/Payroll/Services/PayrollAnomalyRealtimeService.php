<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Services\OutboxService;
use App\Modules\Payroll\Events\PayrollAnomalyChanged;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable refresh publisher for review-list and finalization-gate changes. */
final class PayrollAnomalyRealtimeService
{
    public function stage(PayrollPeriod $period, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new PayrollAnomalyChanged(
            periodHashId: (string) $period->hash_id,
            action: $action,
            version: now()->format('Y-m-d\\TH:i:s.uP'),
            timestamp: now()->toISOString(),
        ), "payroll-anomaly-refresh:{$period->getKey()}:{$transactionId}");
    }
}
