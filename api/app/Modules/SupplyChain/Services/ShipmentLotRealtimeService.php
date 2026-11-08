<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Services\OutboxService;
use App\Modules\SupplyChain\Events\ShipmentLotChanged;
use App\Modules\SupplyChain\Models\ShipmentLot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ShipmentLotRealtimeService
{
    public function stage(ShipmentLot $lot, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $dedupeKey = "shipment-lot-refresh:{$lot->getKey()}:{$transactionId}";

        app(OutboxService::class)->recordCoalesced(new ShipmentLotChanged(
            shipmentLotHashId: (string) $lot->hash_id,
            action: $action,
            version: $lot->updated_at?->toISOString() ?? now()->toISOString(),
            actorId: auth()->id() ? (int) auth()->id() : null,
        ), $dedupeKey);
    }
}
