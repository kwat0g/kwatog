<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Events\MrpPlanChanged;
use App\Modules\MRP\Models\MrpPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one durable plan refresh per row and transaction. */
final class MrpPlanRealtimeService
{
    public function stage(MrpPlan $plan, string $action): void
    {
        $version = $plan->fresh()?->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? $plan->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $salesOrderHashId = SalesOrder::query()->find($plan->sales_order_id)?->hash_id;
        if (! is_string($salesOrderHashId) || $salesOrderHashId === '') {
            throw new \RuntimeException("Cannot stage an MRP plan refresh for missing sales order {$plan->sales_order_id}.");
        }

        app(OutboxService::class)->recordCoalesced(new MrpPlanChanged(
            planHashId: (string) $plan->hash_id,
            salesOrderHashId: $salesOrderHashId,
            action: $action,
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "mrp-plan-refresh:{$plan->getKey()}:{$transactionId}");
    }
}
