<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Observers;

use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\Vehicle;
use App\Modules\SupplyChain\Services\VehicleRealtimeService;

/** Refreshes vehicles when delivery assignment or active-state eligibility changes. */
final class DeliveryVehicleRealtimeObserver
{
    public function created(Delivery $delivery): void
    {
        $this->stage($delivery->vehicle_id === null ? null : (int) $delivery->vehicle_id, $delivery);
    }

    public function updated(Delivery $delivery): void
    {
        if (! $delivery->wasChanged(['vehicle_id', 'status', 'deleted_at'])) {
            return;
        }

        $version = $delivery->updated_at?->toISOString();
        $currentVehicleId = $delivery->vehicle_id === null ? null : (int) $delivery->vehicle_id;
        $previous = $delivery->getPrevious();
        $previousVehicleId = $delivery->wasChanged('vehicle_id') && ($previous['vehicle_id'] ?? null) !== null
            ? (int) $previous['vehicle_id']
            : ($delivery->wasChanged('vehicle_id') ? null : $currentVehicleId);

        foreach (array_unique(array_filter([$currentVehicleId, $previousVehicleId])) as $vehicleId) {
            $this->stage((int) $vehicleId, $delivery, $version);
        }
    }

    public function deleted(Delivery $delivery): void
    {
        $this->stage($delivery->vehicle_id === null ? null : (int) $delivery->vehicle_id, $delivery);
    }

    public function forceDeleted(Delivery $delivery): void
    {
        $this->deleted($delivery);
    }

    private function stage(?int $vehicleId, Delivery $delivery, ?string $version = null): void
    {
        if ($vehicleId === null) {
            return;
        }

        $vehicle = Vehicle::withTrashed()->find($vehicleId);
        if ($vehicle !== null) {
            app(VehicleRealtimeService::class)->stage(
                $vehicle,
                'updated',
                $version ?? $delivery->updated_at?->toISOString(),
            );
        }
    }
}
