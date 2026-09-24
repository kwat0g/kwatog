<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\TrashedFilter;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    public function tree(): Collection
    {
        // zones.locations.zone.warehouse is required so WarehouseLocationResource and
        // its `full_code` accessor can render without a lazy load (strict-mode enabled).
        return Warehouse::query()
            ->with([
                'zones' => fn ($q) => $q->orderBy('code'),
                'zones.locations' => fn ($q) => $q->orderBy('code'),
                'zones.locations.zone.warehouse',
            ])
            ->orderBy('name')
            ->get();
    }

    public function listWarehouses(array $filters = []): Collection
    {
        $q = Warehouse::query();
        TrashedFilter::apply($q, $filters);
        return $q->orderBy('name')->get();
    }

    public function createWarehouse(array $data): Warehouse
    {
        return DB::transaction(fn () => Warehouse::create($data));
    }

    public function updateWarehouse(Warehouse $w, array $data): Warehouse
    {
        return DB::transaction(function () use ($w, $data) {
            $locked = Warehouse::query()->lockForUpdate()->findOrFail($w->id);
            $deactivating = array_key_exists('is_active', $data)
                && filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) === false
                && $locked->is_active;
            if ($deactivating) {
                $locationIds = WarehouseLocation::query()
                    ->whereIn('zone_id', WarehouseZone::query()->select('id')->where('warehouse_id', $locked->id))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');
                if (StockLevel::query()
                    ->whereIn('location_id', $locationIds)
                    ->where(fn ($query) => $query->where('quantity', '>', 0)->orWhere('reserved_quantity', '>', 0))
                    ->exists()) {
                    throw new BusinessRuleException('Cannot deactivate a warehouse with stock or reservations. Move or release its inventory first.');
                }
            }

            $locked->update($data);
            return $locked->fresh();
        });
    }

    public function deleteWarehouse(Warehouse $w): void
    {
        DB::transaction(function () use ($w): void {
            $locked = Warehouse::query()->lockForUpdate()->findOrFail($w->id);
            $locationIds = WarehouseLocation::query()
                ->whereIn('zone_id', WarehouseZone::query()->select('id')->where('warehouse_id', $locked->id))
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
            $hasStock = StockLevel::query()
                ->whereIn('location_id', $locationIds)
                ->where(fn ($query) => $query->where('quantity', '>', 0)->orWhere('reserved_quantity', '>', 0))
                ->exists();
            if ($hasStock) throw new BusinessRuleException('Cannot delete a warehouse with stock or reservations. Deactivate instead.');
            $locked->delete();
        });
    }

    public function createZone(array $data): WarehouseZone
    {
        return DB::transaction(fn () => WarehouseZone::create($data));
    }

    public function updateZone(WarehouseZone $z, array $data): WarehouseZone
    {
        return DB::transaction(function () use ($z, $data) {
            $locked = WarehouseZone::query()->lockForUpdate()->findOrFail($z->id);
            $locationIds = $locked->locations()->orderBy('id')->lockForUpdate()->pluck('id');
            $zoneType = $data['zone_type'] ?? null;
            $zoneType = $zoneType instanceof WarehouseZoneType ? $zoneType->value : $zoneType;
            $changesInventoryClass = (
                $zoneType !== null
                && $zoneType !== $locked->zone_type->value
            ) || (
                array_key_exists('warehouse_id', $data)
                && (int) $data['warehouse_id'] !== (int) $locked->warehouse_id
            );

            if ($changesInventoryClass && StockLevel::query()
                ->whereIn('location_id', $locationIds)
                ->where(fn ($query) => $query->where('quantity', '>', 0)->orWhere('reserved_quantity', '>', 0))
                ->exists()) {
                throw new BusinessRuleException('Cannot reclassify a zone with stock or reservations. Move or release its inventory first.');
            }

            $locked->update($data);

            return $locked->fresh();
        });
    }

    public function deleteZone(WarehouseZone $z): void
    {
        DB::transaction(function () use ($z): void {
            $locked = WarehouseZone::query()->lockForUpdate()->findOrFail($z->id);
            $locIds = $locked->locations()->orderBy('id')->lockForUpdate()->pluck('id');
            $hasStock = StockLevel::query()
                ->whereIn('location_id', $locIds)
                ->where(fn ($query) => $query->where('quantity', '>', 0)->orWhere('reserved_quantity', '>', 0))
                ->exists();
            if ($hasStock) throw new BusinessRuleException('Cannot delete a zone with stock or reservations.');
            $locked->delete();
        });
    }

    public function createLocation(array $data): WarehouseLocation
    {
        return DB::transaction(function () use ($data) {
            $this->assertActiveZone((int) $data['zone_id']);

            return WarehouseLocation::create($data);
        });
    }

    public function updateLocation(WarehouseLocation $l, array $data): WarehouseLocation
    {
        return DB::transaction(function () use ($l, $data) {
            $locked = WarehouseLocation::query()->lockForUpdate()->findOrFail($l->id);
            $targetZoneId = array_key_exists('zone_id', $data)
                ? (int) $data['zone_id']
                : (int) $locked->zone_id;
            $this->assertActiveZone($targetZoneId);
            $hasStock = StockLevel::query()
                ->where('location_id', $locked->id)
                ->where(fn ($query) => $query->where('quantity', '>', 0)->orWhere('reserved_quantity', '>', 0))
                ->exists();

            if ($targetZoneId !== (int) $locked->zone_id && $hasStock) {
                throw new BusinessRuleException('Cannot move a location with stock or reservations to another zone. Move or release its inventory first.');
            }
            $deactivating = array_key_exists('is_active', $data)
                && filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) === false
                && $locked->is_active;
            if ($deactivating && $hasStock) {
                throw new BusinessRuleException('Cannot deactivate a location with stock or reservations. Move or release its inventory first.');
            }

            $locked->update($data);

            return $locked->fresh(['zone.warehouse']);
        });
    }

    public function restoreWarehouse(Warehouse $warehouse): Warehouse
    {
        return DB::transaction(function () use ($warehouse) {
            $locked = Warehouse::withTrashed()->lockForUpdate()->findOrFail($warehouse->id);
            $locked->restore();

            return $locked->fresh();
        });
    }

    public function restoreZone(WarehouseZone $zone): WarehouseZone
    {
        return DB::transaction(function () use ($zone) {
            $locked = WarehouseZone::withTrashed()->lockForUpdate()->findOrFail($zone->id);
            $warehouse = Warehouse::query()->find($locked->warehouse_id);
            if (! $warehouse || ! $warehouse->is_active) {
                throw new BusinessRuleException('Restore the active parent warehouse before restoring this zone.');
            }

            $locked->restore();

            return $locked->fresh(['warehouse']);
        });
    }

    public function restoreLocation(WarehouseLocation $location): WarehouseLocation
    {
        return DB::transaction(function () use ($location) {
            $locked = WarehouseLocation::withTrashed()->lockForUpdate()->findOrFail($location->id);
            $zone = WarehouseZone::query()->with('warehouse')->find($locked->zone_id);
            if (! $zone || ! $zone->warehouse?->is_active) {
                throw new BusinessRuleException('Restore the active parent warehouse and zone before restoring this location.');
            }

            $locked->restore();

            return $locked->fresh(['zone.warehouse']);
        });
    }

    private function assertActiveZone(int $zoneId): WarehouseZone
    {
        $zone = WarehouseZone::query()->with('warehouse')->find($zoneId);
        if (! $zone || ! $zone->warehouse?->is_active) {
            throw new BusinessRuleException('The target zone and warehouse must be active.');
        }

        return $zone;
    }

    public function deleteLocation(WarehouseLocation $l): void
    {
        DB::transaction(function () use ($l): void {
            $locked = WarehouseLocation::query()->lockForUpdate()->findOrFail($l->id);
            if (StockLevel::query()
                ->where('location_id', $locked->id)
                ->where(fn ($query) => $query->where('quantity', '>', 0)->orWhere('reserved_quantity', '>', 0))
                ->exists()) {
                throw new BusinessRuleException('Cannot delete a location with stock or reservations.');
            }
            $locked->delete();
        });
    }
}
