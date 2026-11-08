<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Events\VehicleChanged;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces multiple updates to one vehicle inside a transaction into one signal. */
final class VehicleRealtimeService
{
    public function stage(Vehicle $vehicle, string $action, ?string $sourceVersion = null): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();

        app(OutboxService::class)->recordCoalesced(
            new VehicleChanged(
                vehicleHashId: (string) $vehicle->hash_id,
                action: $action,
                version: $sourceVersion ?? $vehicle->updated_at?->toISOString() ?? now()->toISOString(),
                actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
            ),
            "vehicle-refresh:{$vehicle->getKey()}:{$transactionId}",
        );
    }
}
