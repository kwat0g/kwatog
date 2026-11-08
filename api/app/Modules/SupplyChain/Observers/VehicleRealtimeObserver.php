<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Observers;

use App\Modules\SupplyChain\Models\Vehicle;
use App\Modules\SupplyChain\Services\VehicleRealtimeService;

final class VehicleRealtimeObserver
{
    public function created(Vehicle $vehicle): void
    {
        app(VehicleRealtimeService::class)->stage($vehicle, 'created');
    }

    public function updated(Vehicle $vehicle): void
    {
        $action = $vehicle->wasChanged('deleted_at') && $vehicle->deleted_at === null
            ? 'restored'
            : 'updated';

        app(VehicleRealtimeService::class)->stage($vehicle, $action);
    }

    public function deleted(Vehicle $vehicle): void
    {
        app(VehicleRealtimeService::class)->stage($vehicle, 'deleted');
    }
}
