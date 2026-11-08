<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\OutboxService;
use App\Modules\Inventory\Events\StockAdjustmentChanged;
use App\Modules\Inventory\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces one adjustment's writes into a durable after-commit refresh ping. */
final class StockAdjustmentRealtimeService
{
    public function stage(StockAdjustment $adjustment, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new StockAdjustmentChanged(
            entityHashId: (string) $adjustment->hash_id,
            action: $action,
            version: $adjustment->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), "stock-adjustment-refresh:{$adjustment->getKey()}:{$transactionId}");
    }
}
