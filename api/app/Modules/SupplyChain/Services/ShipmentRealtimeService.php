<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Events\ShipmentChanged;
use App\Modules\SupplyChain\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces shipment and child-row writes into one durable refresh per transaction. */
final class ShipmentRealtimeService
{
    public function stage(Shipment $shipment, string $action, ?string $sourceVersion = null): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(
            new ShipmentChanged(
                shipmentHashId: (string) $shipment->hash_id,
                action: $action,
                version: $sourceVersion ?? $shipment->updated_at?->toISOString() ?? now()->toISOString(),
                actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
            ),
            "shipment-refresh:{$shipment->getKey()}:{$transactionId}",
        );
    }
}
