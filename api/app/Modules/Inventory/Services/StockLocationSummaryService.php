<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Quality\Services\QualityStockHoldService;
use App\Modules\SupplyChain\Models\DeliveryStockReservation;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Derives bin occupancy from the stock ledger.
 *
 * `warehouse_locations.current_*` is a legacy denormalized projection and is
 * intentionally not consulted here. A location may contain more than one
 * item, so the stock-level rows are the only source that can represent the
 * complete current occupancy. Lot data is a suggestion only and is derived
 * from the net movement history for the item/location pair.
 */
class StockLocationSummaryService
{
    /** Physical totals and stock that can be reserved now, in base units. */
    public function availability(Collection $stockLevels, ?Collection $lotMovements = null): array
    {
        (new EloquentCollection($stockLevels->all()))->loadMissing('location.zone.warehouse');
        $lotMovements ??= StockMovement::query()->whereIn('item_id', $stockLevels->pluck('item_id')->unique())
            ->whereNotNull('lot_number')->where(fn ($q) => $q->whereIn('from_location_id', $stockLevels->pluck('location_id'))
            ->orWhereIn('to_location_id', $stockLevels->pluck('location_id')))->orderBy('created_at')->orderBy('id')->get();
        $totals = array_fill_keys([
            'on_hand_quantity', 'reserved_quantity', 'quality_held_quantity',
            'unusable_zone_quantity', 'expired_quantity', 'usable_quantity',
        ], '0.000');
        foreach ($stockLevels as $level) {
            $quantity = (string) $level->quantity;
            $reserved = (string) $level->reserved_quantity;
            $held = app(QualityStockHoldService::class)->heldQuantity((int) $level->item_id, (int) $level->location_id);
            $totals['on_hand_quantity'] = bcadd($totals['on_hand_quantity'], $quantity, 3);
            $totals['reserved_quantity'] = bcadd($totals['reserved_quantity'], $reserved, 3);
            $totals['quality_held_quantity'] = bcadd($totals['quality_held_quantity'], $held, 3);
            $expired = '0.000';
            $expiredExcluded = '0.000';
            foreach ($this->lots((int) $level->item_id, (int) $level->location_id, $lotMovements->filter(fn ($movement) => (int) $movement->item_id === (int) $level->item_id && ((int) $movement->from_location_id === (int) $level->location_id || (int) $movement->to_location_id === (int) $level->location_id))) as $lot) {
                if (! $this->isExpired($lot['expiry_date'])) {
                    continue;
                }
                $expired = bcadd($expired, $lot['net'], 3);
                $lotHeld = app(QualityStockHoldService::class)->heldQuantity((int) $level->item_id, (int) $level->location_id, $lot['lot_number']);
                $deliveryHeld = DeliveryStockReservation::query()
                    ->where('item_id', $level->item_id)->where('location_id', $level->location_id)
                    ->where('lot_number', $lot['lot_number'])->where('status', 'reserved')->get()
                    ->reduce(fn (string $sum, $hold): string => bcadd($sum, $hold->remainingQuantity(), 3), '0.000');
                $additional = bcsub(bcsub($lot['net'], $lotHeld, 3), $deliveryHeld, 3);
                if (bccomp($additional, '0', 3) > 0) {
                    $expiredExcluded = bcadd($expiredExcluded, $additional, 3);
                }
            }
            $totals['expired_quantity'] = bcadd($totals['expired_quantity'], $expired, 3);
            $location = $level->location;
            $zone = $location?->zone;
            if (! $location?->is_active || $location?->is_blocked || ! $zone?->warehouse?->is_active
                || in_array($zone?->zone_type, [WarehouseZoneType::Quarantine, WarehouseZoneType::Scrap], true)) {
                $totals['unusable_zone_quantity'] = bcadd($totals['unusable_zone_quantity'], $quantity, 3);

                continue;
            }
            $usable = bcsub(bcsub(bcsub($quantity, $reserved, 3), $held, 3), $expiredExcluded, 3);
            if (bccomp($usable, '0', 3) > 0) {
                $totals['usable_quantity'] = bcadd($totals['usable_quantity'], $usable, 3);
            }
        }

        return $totals;
    }

    /** Batch lot-history reads for dashboard and alert item groups. */
    public function availabilityByItem(Collection $stockLevels): Collection
    {
        $movements = StockMovement::query()->whereIn('item_id', $stockLevels->pluck('item_id')->unique())
            ->whereNotNull('lot_number')->orderBy('created_at')->orderBy('id')->get();

        return $stockLevels->groupBy('item_id')->map(fn ($levels) => $this->availability($levels,
            $movements->filter(fn ($movement) => (int) $movement->item_id === (int) $levels->first()->item_id)));
    }

    /** Batch lot-history reads while keeping each stock bin's availability separate. */
    public function availabilityByStockLevel(Collection $stockLevels): Collection
    {
        if ($stockLevels->isEmpty()) {
            return collect();
        }

        $locationIds = $stockLevels->pluck('location_id')->unique();
        $movements = StockMovement::query()->whereIn('item_id', $stockLevels->pluck('item_id')->unique())
            ->whereNotNull('lot_number')
            ->where(fn ($query) => $query->whereIn('from_location_id', $locationIds)->orWhereIn('to_location_id', $locationIds))
            ->orderBy('created_at')->orderBy('id')->get();
        $movementsByItem = $movements->groupBy('item_id');

        return $stockLevels->mapWithKeys(function (StockLevel $level) use ($movementsByItem): array {
            $levelMovements = $movementsByItem->get($level->item_id, collect())->filter(
                fn (StockMovement $movement): bool => (int) $movement->from_location_id === (int) $level->location_id
                    || (int) $movement->to_location_id === (int) $level->location_id,
            );

            return [$level->getKey() => $this->availability(collect([$level]), $levelMovements)];
        });
    }

    /** Expiry dates remain valid through that date in the business timezone. */
    public function isExpired(?string $expiryDate): bool
    {
        return $expiryDate !== null && $expiryDate < now()->timezone(config('app.timezone'))->toDateString();
    }

    /**
     * @param  Collection<int, StockLevel>  $stockLevels
     * @return array{current_item: mixed, current_quantity: string, current_lot_number: ?string, current_expiry_date: ?string, total_quantity: string}
     */
    public function summarize(Collection $stockLevels): array
    {
        $totalQuantity = '0';
        foreach ($stockLevels as $stockLevel) {
            $totalQuantity = bcadd($totalQuantity, (string) $stockLevel->quantity, 3);
        }

        $positiveLevels = $stockLevels
            ->filter(static fn (StockLevel $level): bool => bccomp((string) $level->quantity, '0', 3) > 0)
            ->values();
        $primary = $positiveLevels->count() === 1 ? $positiveLevels->first() : null;
        $lot = $primary
            ? $this->preferredLot((int) $primary->item_id, (int) $primary->location_id)
            : null;

        return [
            'current_item' => $primary?->item,
            'current_quantity' => $totalQuantity,
            'current_lot_number' => $lot['lot_number'] ?? null,
            'current_expiry_date' => $lot['expiry_date'] ?? null,
            'total_quantity' => $totalQuantity,
        ];
    }

    /**
     * Return the FEFO/FIFO lot that still has a positive net movement balance
     * at a location. Unknown-lot movements remain outside the lot suggestion,
     * while the authoritative quantity continues to come from stock_levels.
     *
     * @return array{lot_number: string, expiry_date: ?string}|null
     */
    public function preferredLot(int $itemId, int $locationId): ?array
    {
        $lots = $this->lots($itemId, $locationId);

        return $lots === [] ? null : ['lot_number' => $lots[0]['lot_number'], 'expiry_date' => $lots[0]['expiry_date']];
    }

    /** Positive lot balances in FEFO/FIFO order. */
    public function lots(int $itemId, int $locationId, ?Collection $movements = null): array
    {
        $movements ??= StockMovement::query()
            ->where('item_id', $itemId)
            ->whereNotNull('lot_number')
            ->where(function ($query) use ($locationId): void {
                $query->where('from_location_id', $locationId)
                    ->orWhere('to_location_id', $locationId);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'from_location_id', 'to_location_id', 'quantity', 'lot_number', 'expiry_date', 'created_at']);

        /** @var array<string, array{lot_number: string, net: string, expiry_date: ?string, last_movement: int}> $lots */
        $lots = [];
        foreach ($movements as $movement) {
            $lotNumber = (string) $movement->lot_number;
            $lots[$lotNumber] ??= [
                'lot_number' => $lotNumber,
                'net' => '0',
                'expiry_date' => null,
                'last_movement' => 0,
            ];

            if ((int) $movement->to_location_id === $locationId) {
                $lots[$lotNumber]['net'] = bcadd($lots[$lotNumber]['net'], (string) $movement->quantity, 3);
            }
            if ((int) $movement->from_location_id === $locationId) {
                $lots[$lotNumber]['net'] = bcsub($lots[$lotNumber]['net'], (string) $movement->quantity, 3);
            }

            $expiryDate = $movement->expiry_date?->toDateString();
            if ($expiryDate !== null && ($lots[$lotNumber]['expiry_date'] === null || $expiryDate < $lots[$lotNumber]['expiry_date'])) {
                $lots[$lotNumber]['expiry_date'] = $expiryDate;
            }
            $lots[$lotNumber]['last_movement'] = (int) $movement->getKey();
        }

        $available = array_values(array_filter(
            $lots,
            static fn (array $lot): bool => bccomp($lot['net'], '0', 3) > 0,
        ));
        usort($available, static function (array $left, array $right): int {
            if ($left['expiry_date'] === null && $right['expiry_date'] !== null) {
                return 1;
            }
            if ($left['expiry_date'] !== null && $right['expiry_date'] === null) {
                return -1;
            }
            if ($left['expiry_date'] !== $right['expiry_date']) {
                return strcmp((string) $left['expiry_date'], (string) $right['expiry_date']);
            }

            return $left['last_movement'] <=> $right['last_movement'];
        });

        return $available;
    }

    /** Current ledger-backed quantity for one lot at one location. */
    public function lotQuantity(int $itemId, int $locationId, string $lotNumber): string
    {
        $quantity = '0.000';
        $movements ??= StockMovement::query()
            ->where('item_id', $itemId)
            ->where('lot_number', $lotNumber)
            ->where(function ($query) use ($locationId): void {
                $query->where('from_location_id', $locationId)
                    ->orWhere('to_location_id', $locationId);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['quantity', 'from_location_id', 'to_location_id']);

        foreach ($movements as $movement) {
            if ((int) $movement->to_location_id === $locationId) {
                $quantity = bcadd($quantity, (string) $movement->quantity, 3);
            }
            if ((int) $movement->from_location_id === $locationId) {
                $quantity = bcsub($quantity, (string) $movement->quantity, 3);
            }
        }

        return $quantity;
    }
}
