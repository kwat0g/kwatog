<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Observers;

use App\Modules\SupplyChain\Models\Container;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\ShipmentDocument;
use App\Modules\SupplyChain\Models\ShipmentLandedCost;
use App\Modules\SupplyChain\Services\ShipmentRealtimeService;
use Illuminate\Database\Eloquent\Model;

/** Routes nested shipment component changes through the parent shipment signal. */
final class ShipmentComponentRealtimeObserver
{
    public function created(Model $component): void
    {
        $this->stage($component, 'updated');
    }

    public function updated(Model $component): void
    {
        $this->stage($component, 'updated');
    }

    public function deleted(Model $component): void
    {
        $this->stage($component, 'updated');
    }

    private function stage(Model $component, string $action): void
    {
        if (! $component instanceof Container
            && ! $component instanceof ShipmentDocument
            && ! $component instanceof ShipmentLandedCost) {
            return;
        }

        $shipment = Shipment::withTrashed()->find((int) $component->shipment_id);
        if ($shipment !== null) {
            app(ShipmentRealtimeService::class)->stage(
                $shipment,
                $action,
                $component->updated_at?->toISOString(),
            );
        }
    }
}
