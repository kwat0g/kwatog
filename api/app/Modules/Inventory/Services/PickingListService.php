<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PickingListService
{
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
            'work_order'   => $mis->work_order_id ? "#{$mis->work_order_id}" : ($mis->reference_text ?? 'N/A'),
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
        $expirySub = $this->buildExpirySubquery($itemId);

        // Get stock levels with available quantity > 0, ordered by expiry then FIFO.
        $stockLevels = StockLevel::query()
            ->where('item_id', $itemId)
            ->whereRaw('(quantity - reserved_quantity) > 0')
            ->with('location.zone.warehouse')
            ->leftJoinSub($expirySub, 'expiry_info', function ($join) {
                $join->on('stock_levels.location_id', '=', 'expiry_info.location_id');
            })
            ->select([
                'stock_levels.*',
                'expiry_info.earliest_expiry_date',
            ])
            ->orderByRaw('CASE WHEN expiry_info.earliest_expiry_date IS NULL THEN 1 ELSE 0 END')  // expiring first
            ->orderBy('expiry_info.earliest_expiry_date')                                          // earliest expiry first
            ->orderBy('stock_levels.created_at')                                                   // fallback FIFO
            ->get();

        $suggestions = [];
        $remaining = $requiredQty;

        foreach ($stockLevels as $sl) {
            if (bccomp($remaining, '0', 3) <= 0) break;

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
                'lot_number'         => $sl->location->current_lot_number,
            ];

            // Annotate FEFO info when the stock at this location has an expiry date.
            if ($sl->earliest_expiry_date) {
                $suggestion['picking_method'] = 'FEFO';
                $suggestion['expires_on'] = $sl->earliest_expiry_date;
            } else {
                $suggestion['picking_method'] = 'FIFO';
            }

            $suggestions[] = $suggestion;

            $remaining = bcsub((string) $remaining, (string) $pickQty, 3);
        }

        return $suggestions;
    }

    /**
     * Build a subquery that returns the earliest expiry_date per location
     * for the given item, based on inbound StockMovement rows.
     *
     * When a GRN is accepted, StockMovementService::move() creates a receipt
     * movement and StockMovementService::stampLot() copies the GrnItem.expiry_date
     * onto StockMovement.expiry_date. This subquery aggregates those inbound
     * movements (per item + location) to find the soonest-expiring lot at each
     * location.
     */
    private function buildExpirySubquery(int $itemId): \Illuminate\Database\Query\Builder
    {
        return StockMovement::query()
            ->select([
                'to_location_id as location_id',
                DB::raw('MIN(expiry_date) as earliest_expiry_date'),
            ])
            ->where('item_id', $itemId)
            ->whereNotNull('expiry_date')
            ->whereNotNull('to_location_id')
            ->groupBy('to_location_id');
    }
}
