<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Observers;

use App\Modules\SupplyChain\Models\ShipmentLot;
use App\Modules\SupplyChain\Services\ShipmentLotRealtimeService;

final class ShipmentLotRealtimeObserver
{
    public function created(ShipmentLot $lot): void
    {
        app(ShipmentLotRealtimeService::class)->stage($lot, 'created');
    }

    public function updated(ShipmentLot $lot): void
    {
        app(ShipmentLotRealtimeService::class)->stage($lot, 'updated');
    }

    public function deleted(ShipmentLot $lot): void
    {
        app(ShipmentLotRealtimeService::class)->stage($lot, 'deleted');
    }
}
