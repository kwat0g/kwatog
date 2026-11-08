<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\WarehouseLayoutRealtimeService;
use Illuminate\Database\Eloquent\Model;

/** Captures warehouse, zone and location lifecycle writes from Eloquent paths. */
final class WarehouseLayoutRealtimeObserver
{
    public function created(Model $model): void
    {
        $this->stage($model, 'created');
    }

    public function updated(Model $model): void
    {
        $action = $model->wasChanged('deleted_at') && $model->getAttribute('deleted_at') === null
            ? 'restored'
            : 'updated';

        $this->stage($model, $action);
    }

    public function deleted(Model $model): void
    {
        $this->stage($model, 'deleted');
    }

    private function stage(Model $model, string $action): void
    {
        if (! $model instanceof Warehouse && ! $model instanceof WarehouseZone && ! $model instanceof WarehouseLocation) {
            return;
        }

        app(WarehouseLayoutRealtimeService::class)->stage($model, $action);
    }
}
