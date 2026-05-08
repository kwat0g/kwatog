<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Series F — Task F3. Stock Card.
 *
 * Per-item movement ledger with running balance and weighted-average
 * cost computed inline. No new schema — reads from `stock_movements`
 * + `stock_levels` only.
 *
 * Returns:
 *   - opening    : balance + weighted_avg_cost as of $from (exclusive of $from)
 *   - rows       : in-range movements in chronological order with running totals
 *   - closing    : final balance + cost after the last in-range movement
 */
class StockCardService
{
    /**
     * @return array{
     *   item: array<string, mixed>,
     *   from: string,
     *   to: string,
     *   opening: array<string, string>,
     *   rows: array<int, array<string, mixed>>,
     *   closing: array<string, string>,
     * }
     */
    public function card(Item $item, Carbon $from, Carbon $to, ?int $locationId = null): array
    {
        // 1) Opening: sum(quantity) net for movements BEFORE $from.
        $opening = $this->balanceBefore($item->id, $from, $locationId);

        // 2) In-range movements in ASC order.
        $q = StockMovement::query()
            ->with(['fromLocation.zone.warehouse', 'toLocation.zone.warehouse', 'creator:id,name'])
            ->where('item_id', $item->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($locationId !== null) {
            $q->where(function ($qq) use ($locationId) {
                $qq->where('from_location_id', $locationId)
                   ->orWhere('to_location_id', $locationId);
            });
        }

        $movements = $q->get();

        $balance = (float) $opening['balance'];
        $totalValue = (float) $opening['value'];

        $rows = [];
        foreach ($movements as $m) {
            $direction = $this->direction($m, $locationId);
            $signed = $direction === 'in' ? (float) $m->quantity : -1.0 * (float) $m->quantity;
            $balance += $signed;

            // Recompute weighted avg only on receipts (movement IN). Other
            // movement types preserve the existing weighted-avg cost.
            if ($direction === 'in' && (float) $m->quantity > 0) {
                $totalValue += (float) $m->quantity * (float) $m->unit_cost;
            } elseif ($direction === 'out' && $balance >= 0) {
                $avg = $balance > 0 ? $totalValue / max($balance + (float) $m->quantity, 0.0001) : 0.0;
                $totalValue -= (float) $m->quantity * $avg;
                if ($totalValue < 0) $totalValue = 0;
            }

            $weightedAvg = $balance > 0 ? $totalValue / $balance : (float) $m->unit_cost;

            $rows[] = [
                'id'             => $m->hash_id,
                'date'           => $m->created_at?->toIso8601String(),
                'movement_type'  => (string) $m->movement_type,
                'reference_type' => (string) ($m->reference_type ?? ''),
                'reference_id'   => $m->reference_id !== null
                    ? app('hashids')->encode((int) $m->reference_id)
                    : null,
                'reference_url'  => $this->resolveReferenceUrl($m),
                'in'             => $direction === 'in'  ? (string) $m->quantity : '0',
                'out'            => $direction === 'out' ? (string) $m->quantity : '0',
                'unit_cost'      => (string) $m->unit_cost,
                'balance'        => number_format($balance, 3, '.', ''),
                'weighted_avg'   => number_format($weightedAvg, 4, '.', ''),
                'created_by'     => $m->creator?->name,
                'remarks'        => (string) ($m->remarks ?? ''),
            ];
        }

        $closingValue = $totalValue;
        $closingCost  = $balance > 0 ? $closingValue / $balance : 0;

        return [
            'item' => [
                'id'   => $item->hash_id,
                'code' => $item->code,
                'name' => $item->name,
                'unit_of_measure' => $item->unit_of_measure,
            ],
            'from'    => $from->toDateString(),
            'to'      => $to->toDateString(),
            'opening' => [
                'balance'      => number_format($opening['balance'], 3, '.', ''),
                'weighted_avg' => number_format($opening['weighted_avg'], 4, '.', ''),
                'value'        => number_format($opening['value'], 2, '.', ''),
            ],
            'rows'    => $rows,
            'closing' => [
                'balance'      => number_format($balance, 3, '.', ''),
                'weighted_avg' => number_format($closingCost, 4, '.', ''),
                'value'        => number_format($closingValue, 2, '.', ''),
            ],
        ];
    }

    /**
     * @return array{balance: float, weighted_avg: float, value: float}
     */
    private function balanceBefore(int $itemId, Carbon $cutoff, ?int $locationId): array
    {
        $q = DB::table('stock_movements')
            ->select(['movement_type', 'quantity', 'unit_cost', 'from_location_id', 'to_location_id'])
            ->where('item_id', $itemId)
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($locationId !== null) {
            $q->where(function ($qq) use ($locationId) {
                $qq->where('from_location_id', $locationId)
                   ->orWhere('to_location_id', $locationId);
            });
        }

        $balance = 0.0;
        $totalValue = 0.0;
        foreach ($q->get() as $r) {
            $isIn = $r->to_location_id !== null && ($locationId === null || (int) $r->to_location_id === $locationId);
            $isOut = $r->from_location_id !== null && ($locationId === null || (int) $r->from_location_id === $locationId);

            if ($isIn && ! $isOut) {
                $balance += (float) $r->quantity;
                $totalValue += (float) $r->quantity * (float) $r->unit_cost;
            } elseif ($isOut && ! $isIn) {
                $avg = $balance > 0 ? $totalValue / $balance : (float) $r->unit_cost;
                $balance -= (float) $r->quantity;
                $totalValue -= (float) $r->quantity * $avg;
                if ($balance < 0) $balance = 0;
                if ($totalValue < 0) $totalValue = 0;
            }
        }

        return [
            'balance'      => $balance,
            'weighted_avg' => $balance > 0 ? $totalValue / $balance : 0.0,
            'value'        => $totalValue,
        ];
    }

    private function direction(StockMovement $m, ?int $locationId): string
    {
        if ($locationId !== null) {
            if ((int) ($m->to_location_id ?? 0) === $locationId) return 'in';
            if ((int) ($m->from_location_id ?? 0) === $locationId) return 'out';
        }
        if ($m->to_location_id !== null && $m->from_location_id === null) return 'in';
        if ($m->from_location_id !== null && $m->to_location_id === null) return 'out';
        return 'in';
    }

    private function resolveReferenceUrl(StockMovement $m): ?string
    {
        if ($m->reference_type === null || $m->reference_id === null) return null;
        $hash = app('hashids')->encode((int) $m->reference_id);

        return match ($m->reference_type) {
            'goods_receipt_note', 'GoodsReceiptNote' => "/inventory/grn/{$hash}",
            'material_issue_slip', 'MaterialIssueSlip' => "/inventory/material-issues/{$hash}",
            'work_order', 'WorkOrder' => "/production/work-orders/{$hash}",
            'stock_adjustment', 'StockAdjustment' => "/inventory/movements?adjustment={$hash}",
            default => null,
        };
    }
}
