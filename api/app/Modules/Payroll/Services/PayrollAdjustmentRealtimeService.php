<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Services\OutboxService;
use App\Modules\Payroll\Events\PayrollAdjustmentChanged;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, transaction-coalesced publisher for payroll-adjustment writes. */
final class PayrollAdjustmentRealtimeService
{
    public function stage(PayrollAdjustment $adjustment, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $periodHashId = PayrollPeriod::query()->find($adjustment->payroll_period_id)?->hash_id;

        app(OutboxService::class)->recordCoalesced(new PayrollAdjustmentChanged(
            hashId: (string) $adjustment->hash_id,
            action: $action,
            version: $adjustment->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
            actorId: auth()->user()?->hash_id,
            periodHashId: $periodHashId === null ? null : (string) $periodHashId,
            timestamp: now()->toISOString(),
        ), "payroll-adjustment-refresh:{$adjustment->getKey()}:{$transactionId}");
    }
}
