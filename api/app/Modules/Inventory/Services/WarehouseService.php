<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WarehouseService
{
    public function tree(): Collection
    {
        return Warehouse::query()
            ->with(['zones.locations'])
            ->orderBy('name')
            ->get();
    }

    public function listWarehouses(): Collection
    {
        return Warehouse::query()->orderBy('name')->get();
    }

    public function createWarehouse(array $data): Warehouse
    {
        return DB::transaction(fn () => Warehouse::create($data));
    }

    public function updateWarehouse(Warehouse $w, array $data): Warehouse
    {
        return DB::transaction(function () use ($w, $data) {
            $w->update($data);
            return $w->fresh();
        });
    }

    public function deleteWarehouse(Warehouse $w): void
    {
        $hasStock = StockLevel::query()
            ->whereIn('location_id', $w->zones()->with('locations:id,zone_id')->get()
                ->flatMap(fn ($z) => $z->locations->pluck('id')))
            ->where('quantity', '>', 0)->exists();
        if ($hasStock) throw new RuntimeException('Cannot delete a warehouse with stock. Deactivate instead.');
        $w->delete();
    }

    public function createZone(array $data): WarehouseZone
    {
        return DB::transaction(fn () => WarehouseZone::create($data));
    }

    public function updateZone(WarehouseZone $z, array $data): WarehouseZone
    {
        return DB::transaction(function () use ($z, $data) {
            $z->update($data);
            return $z->fresh();
        });
    }

    public function deleteZone(WarehouseZone $z): void
    {
        $locIds = $z->locations()->pluck('id');
        $hasStock = StockLevel::query()->whereIn('location_id', $locIds)->where('quantity', '>', 0)->exists();
        if ($hasStock) throw new RuntimeException('Cannot delete a zone with stock.');
        $z->delete();
    }

    public function createLocation(array $data): WarehouseLocation
    {
        return DB::transaction(fn () => WarehouseLocation::create($data));
    }

    public function updateLocation(WarehouseLocation $l, array $data): WarehouseLocation
    {
        return DB::transaction(function () use ($l, $data) {
            $l->update($data);
            return $l->fresh();
        });
    }

    public function deleteLocation(WarehouseLocation $l): void
    {
        if (StockLevel::query()->where('location_id', $l->id)->where('quantity', '>', 0)->exists()) {
            throw new RuntimeException('Cannot delete a location with stock.');
        }
        $l->delete();
    }
}
