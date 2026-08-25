<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Collection;

class PickingListService
{
    public function __construct(private readonly StockLocationSummaryService $locationSummary) {}

    /**
     * Generate a picking list for a Material Issue Slip.
     * Suggests optimal bin locations based on FEFO (first-expiry-first-out, oldest
     * expiry date first), falling back to FIFO (oldest stock first) for items
     * without expiry dates.
     */
    public function generateForMis(int $misId): array
    {
        $mis = MaterialIssueSlip::with(['items.item', 'items.location.zone.warehouse'])->findOrFail($misId);

        $pickingLines = [];
        foreach ($mis->items as $item) {
            $line = $this->suggestPickLocations($item);
            $pickingLines[] = $line;
        }

        return [
            'slip_number'  => $mis->slip_number,
            'work_order'   => $mis->work_order_id ? "#{$mis->work_order_id}" : $mis->reference_text,
            'issued_date'  => $mis->issued_date,
            'lines'        => $pickingLines,
            'total_lines'  => count($pickingLines),
            'total_items'  => array_sum(array_column($pickingLines, 'quantity_required')),
        ];
    }

    /**
     * Generate picking list for a work order from its bill of materials (future use).
     */
    public function generateForWorkOrder(int $workOrderId, array $materials): array
    {
        $pickingLines = [];
        foreach ($materials as $material) {
            $itemId = (int) $material['item_id'];
            $qtyRequired = (string) $material['quantity'];
            $suggestions = $this->findBestLocations($itemId, $qtyRequired);
            $pickingLines[] = [
                'item_id'          => $itemId,
                'item_code'        => $material['item_code'] ?? null,
                'item_name'        => $material['item_name'] ?? null,
                'unit_of_measure'  => $material['unit_of_measure'] ?? '',
                'quantity_required' => $qtyRequired,
                'suggestions'      => $suggestions,
            ];
        }

        return [
            'work_order'  => "#{$workOrderId}",
            'lines'       => $pickingLines,
        ];
    }

    /**
     * For a given MaterialIssueSlipItem, find the best location(s) to pick from.
     */
    private function suggestPickLocations(MaterialIssueSlipItem $misItem): array
    {
        $itemId = $misItem->item_id;
        $qtyRequired = (string) $misItem->quantity_issued;

        // If a specific location was set on the MIS item, prefer it
        if ($misItem->location_id) {
            $loc = WarehouseLocation::with('zone.warehouse')->find($misItem->location_id);
            return [
                'item_id'          => $itemId,
                'item_code'        => $misItem->item?->code,
                'item_name'        => $misItem->item?->name,
                'unit_of_measure'  => $misItem->item?->unit_of_measure ?? '',
                'quantity_required' => $qtyRequired,
                'preferred_location' => $loc ? [
                    'id'        => $loc->id,
                    'code'      => $loc->code,
                    'full_code' => $loc->full_code,
                    'zone'      => $loc->zone?->name,
                    'warehouse' => $loc->zone?->warehouse?->name,
                ] : null,
                'suggestions' => $this->findBestLocations($itemId, $qtyRequired),
            ];
        }

        // Auto-suggest best locations
        $suggestions = $this->findBestLocations($itemId, $qtyRequired);
        return [
            'item_id'           => $itemId,
            'item_code'         => $misItem->item?->code,
            'item_name'         => $misItem->item?->name,
            'unit_of_measure'   => $misItem->item?->unit_of_measure ?? '',
            'quantity_required' => $qtyRequired,
            'preferred_location' => $suggestions[0]['location'] ?? null,
            'suggestions'       => $suggestions,
        ];
    }

    /**
     * Find best locations for picking an item, using FEFO (first-expiry-first-out).
     *
     * Sort order:
     * 1. Stock WITH an expiry_date first (prioritise expiring stock).
     * 2. Within expiring stock, earliest expiry_date first.
     * 3. Non-expiring stock (no expiry_date) last, ordered by created_at (FIFO).
     *
     * Fallback: when no stock in the item has any expiry_date, plain FIFO by
     * created_at is preserved.
     */
    private function findBestLocations(int $itemId, string $requiredQty): array
    {
        // Get authoritative stock levels with available quantity > 0. Lot
        // ordering is derived from the same movement ledger used by the map,
        // not from warehouse_locations.current_lot_number.
        $stockLevels = StockLevel::query()
            ->where('item_id', $itemId)
            ->whereRaw('(quantity - reserved_quantity) > 0')
            // REC-08 — never suggest quarantine/scrap-zone stock (held under MRB).
            ->whereHas('location.zone', function ($q) {
                $q->whereNotIn('zone_type', [
                    \App\Modules\Inventory\Enums\WarehouseZoneType::Quarantine->value,
                    \App\Modules\Inventory\Enums\WarehouseZoneType::Scrap->value,
                ]);
            })
            ->with('location.zone.warehouse')
            ->orderBy('stock_levels.created_at')
            ->get();

        $candidates = $stockLevels
            ->map(fn (StockLevel $sl): array => [
                'level' => $sl,
                'lot' => $this->locationSummary->preferredLot($itemId, (int) $sl->location_id),
            ])
            ->sort(function (array $left, array $right): int {
                $leftExpiry = $left['lot']['expiry_date'] ?? null;
                $rightExpiry = $right['lot']['expiry_date'] ?? null;
                if ($leftExpiry === null && $rightExpiry !== null) return 1;
                if ($leftExpiry !== null && $rightExpiry === null) return -1;
                if ($leftExpiry !== $rightExpiry) {
                    return strcmp((string) $leftExpiry, (string) $rightExpiry);
                }
                return $left['level']->created_at <=> $right['level']->created_at;
            })
            ->values();

        $suggestions = [];
        $remaining = $requiredQty;

        foreach ($candidates as $candidate) {
            if (bccomp($remaining, '0', 3) <= 0) break;

            /** @var StockLevel $sl */
            $sl = $candidate['level'];
            $lot = $candidate['lot'];

            if (!$sl->location) continue;

            $available = bcsub((string) $sl->quantity, (string) $sl->reserved_quantity, 3);
            if (bccomp($available, '0', 3) <= 0) continue;

            $pickQty = bccomp($remaining, $available, 3) <= 0 ? $remaining : $available;

            $suggestion = [
                'location' => [
                    'id'        => $sl->location->id,
                    'code'      => $sl->location->code,
                    'full_code' => $sl->location->full_code,
                    'zone'      => $sl->location->zone?->name,
                    'warehouse' => $sl->location->zone?->warehouse?->name,
                    'rack'      => $sl->location->rack,
                    'bin'       => $sl->location->bin,
                ],
                'quantity_available' => $available,
                'quantity_to_pick'   => $pickQty,
                'lot_number'         => $lot['lot_number'] ?? null,
            ];

            // Annotate FEFO info when the stock at this location has an expiry date.
            if ($lot['expiry_date'] ?? null) {
                $suggestion['picking_method'] = 'FEFO';
                $suggestion['expires_on'] = $lot['expiry_date'];
            } else {
                $suggestion['picking_method'] = 'FIFO';
            }

            $suggestions[] = $suggestion;

            $remaining = bcsub((string) $remaining, (string) $pickQty, 3);
        }

        return $suggestions;
    }

}
