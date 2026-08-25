<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
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
    /**
     * @param Collection<int, StockLevel> $stockLevels
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
        $movements = StockMovement::query()
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
            if ($left['expiry_date'] === null && $right['expiry_date'] !== null) return 1;
            if ($left['expiry_date'] !== null && $right['expiry_date'] === null) return -1;
            if ($left['expiry_date'] !== $right['expiry_date']) {
                return strcmp((string) $left['expiry_date'], (string) $right['expiry_date']);
            }
            return $left['last_movement'] <=> $right['last_movement'];
        });

        if ($available === []) {
            return null;
        }

        return [
            'lot_number' => $available[0]['lot_number'],
            'expiry_date' => $available[0]['expiry_date'],
        ];
    }
}
