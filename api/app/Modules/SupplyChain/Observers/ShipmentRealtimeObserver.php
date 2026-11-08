<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Observers;

use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Services\ShipmentRealtimeService;

final class ShipmentRealtimeObserver
{
    public function created(Shipment $shipment): void
    {
        app(ShipmentRealtimeService::class)->stage($shipment, 'created');
    }

    public function updated(Shipment $shipment): void
    {
        app(ShipmentRealtimeService::class)->stage(
            $shipment,
            $shipment->wasChanged('status') ? 'status_changed' : 'updated',
        );
    }

    public function deleted(Shipment $shipment): void
    {
        app(ShipmentRealtimeService::class)->stage($shipment, 'deleted');
    }
}
