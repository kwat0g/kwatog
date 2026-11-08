<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\OutboxService;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Events\WarehouseLayoutChanged;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces layout lifecycle changes into one durable refresh per transaction. */
final class WarehouseLayoutRealtimeService
{
    public function stage(Model $model, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new WarehouseLayoutChanged(
            eventId: (string) Str::uuid(),
            action: $action,
            version: $model->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            timestamp: now()->toISOString(),
            refreshProductionReadiness: $this->affectsFinishedGoodsReadiness($model, $action),
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), "warehouse-layout-refresh:{$transactionId}");
    }

    private function affectsFinishedGoodsReadiness(Model $model, string $action): bool
    {
        $lifecycle = in_array($action, ['created', 'deleted', 'restored'], true);

        if ($model instanceof Warehouse) {
            return ($lifecycle || $model->wasChanged('is_active'))
                && WarehouseZone::withTrashed()
                    ->where('warehouse_id', $model->getKey())
                    ->where('zone_type', WarehouseZoneType::FinishedGoods->value)
                    ->exists();
        }

        if ($model instanceof WarehouseZone) {
            if (! $lifecycle && ! $model->wasChanged(['warehouse_id', 'zone_type', 'deleted_at'])) {
                return false;
            }

            $previous = $model->getPrevious();
            $currentType = $model->zone_type instanceof WarehouseZoneType
                ? $model->zone_type->value
                : $model->getRawOriginal('zone_type');

            return $currentType === WarehouseZoneType::FinishedGoods->value
                || ($previous['zone_type'] ?? null) === WarehouseZoneType::FinishedGoods->value;
        }

        if ($model instanceof WarehouseLocation) {
            if (! $lifecycle && ! $model->wasChanged(['zone_id', 'is_active', 'is_blocked', 'deleted_at'])) {
                return false;
            }

            $zoneIds = collect([$model->zone_id, $model->getPrevious()['zone_id'] ?? null])
                ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
                ->map(fn ($id): int => (int) $id)
                ->unique();

            return $zoneIds->isNotEmpty() && WarehouseZone::withTrashed()
                ->whereKey($zoneIds)
                ->where('zone_type', WarehouseZoneType::FinishedGoods->value)
                ->exists();
        }

        return false;
    }
}
