<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Common\Support\Money;

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
        // Rebuild each location's ledger state so the card follows the same
        // four-decimal WAC rounding as StockMovementService.
        $levels = $this->levelsBefore($item->id, $from, $locationId);
        $opening = $this->summarize($levels);

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

        $rows = [];
        foreach ($movements as $m) {
            $direction = $this->direction($m, $locationId);
            $this->applyMovement($levels, $m, $locationId);
            $current = $this->summarize($levels);
            $weightedAvg = bccomp($current['balance'], '0', 3) > 0
                ? $current['weighted_avg']
                : $this->round4((string) $m->unit_cost);

            $rows[] = [
                'id'             => $m->hash_id,
                'date'           => $m->created_at?->toIso8601String(),
                'movement_type'  => $m->movement_type?->value ?? '',
                'movement_type_label' => Str::headline($m->movement_type?->value ?? ''),
                'reference_type' => (string) ($m->reference_type ?? ''),
                'reference_id'   => $m->reference_id !== null
                    ? app('hashids')->encode((int) $m->reference_id)
                    : null,
                'reference_url'  => $this->resolveReferenceUrl($m),
                'in'             => in_array($direction, ['in', 'transfer'], true)  ? (string) $m->quantity : '0',
                'out'            => in_array($direction, ['out', 'transfer'], true) ? (string) $m->quantity : '0',
                'unit_cost'      => (string) $m->unit_cost,
                'balance'        => $current['balance'],
                'weighted_avg'   => $weightedAvg,
                'created_by'     => $m->creator?->name,
                'remarks'        => (string) ($m->remarks ?? ''),
            ];
        }

        $closing = $this->summarize($levels);

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
                'balance'      => $opening['balance'],
                'weighted_avg' => $opening['weighted_avg'],
                'value'        => Money::round2($opening['value']),
            ],
            'rows'    => $rows,
            'closing' => [
                'balance'      => $closing['balance'],
                'weighted_avg' => $closing['weighted_avg'],
                'value'        => Money::round2($closing['value']),
            ],
        ];
    }

    /**
     * @return array<int, array{quantity: string, weighted_avg_cost: string}>
     */
    private function levelsBefore(int $itemId, Carbon $cutoff, ?int $locationId): array
    {
        $q = DB::table('stock_movements')
            ->select(['quantity', 'unit_cost', 'from_location_id', 'to_location_id'])
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

        $levels = [];
        foreach ($q->get() as $r) {
            $this->applyMovement($levels, $r, $locationId);
        }

        return $levels;
    }

    /**
     * Apply one ledger row to its affected location state. The stock movement
     * ledger stores the issue cost at the source's already-rounded WAC, so
     * each location must retain that WAC across issues and transfers out.
     *
     * @param array<int, array{quantity: string, weighted_avg_cost: string}> $levels
     */
    private function applyMovement(array &$levels, object $movement, ?int $locationId): void
    {
        $quantity = (string) $movement->quantity;
        $fromId = $movement->from_location_id === null ? null : (int) $movement->from_location_id;
        $toId = $movement->to_location_id === null ? null : (int) $movement->to_location_id;

        if ($fromId !== null && ($locationId === null || $fromId === $locationId)) {
            $levels[$fromId] ??= ['quantity' => '0.000', 'weighted_avg_cost' => '0.0000'];
            $levels[$fromId]['quantity'] = bcsub($levels[$fromId]['quantity'], $quantity, 3);
            if (bccomp($levels[$fromId]['quantity'], '0', 3) < 0) {
                $levels[$fromId]['quantity'] = '0.000';
            }
        }

        if ($toId !== null && ($locationId === null || $toId === $locationId)) {
            $levels[$toId] ??= ['quantity' => '0.000', 'weighted_avg_cost' => '0.0000'];
            $oldQuantity = $levels[$toId]['quantity'];
            $newQuantity = bcadd($oldQuantity, $quantity, 3);
            $oldValue = bcmul($oldQuantity, $levels[$toId]['weighted_avg_cost'], 4);
            $incomingValue = bcmul($quantity, (string) $movement->unit_cost, 4);
            $newValue = bcadd($oldValue, $incomingValue, 4);

            if (bccomp($newQuantity, '0', 3) > 0) {
                $levels[$toId]['weighted_avg_cost'] = $this->round4(bcdiv($newValue, $newQuantity, 6));
            }
            $levels[$toId]['quantity'] = $newQuantity;
        }
    }

    /** @param array<int, array{quantity: string, weighted_avg_cost: string}> $levels
     *  @return array{balance: string, weighted_avg: string, value: string}
     */
    private function summarize(array $levels): array
    {
        $balance = '0.000';
        $value = '0.0000000';
        foreach ($levels as $level) {
            $balance = bcadd($balance, $level['quantity'], 3);
            $value = bcadd($value, bcmul($level['quantity'], $level['weighted_avg_cost'], 7), 7);
        }

        return [
            'balance' => $balance,
            'weighted_avg' => bccomp($balance, '0', 3) > 0
                ? $this->round4(bcdiv($value, $balance, 6))
                : '0.0000',
            'value' => $value,
        ];
    }

    private function round4(string $value): string
    {
        return bcadd($value, '0.00005', 4);
    }

    private function direction(StockMovement $m, ?int $locationId): string
    {
        if ($locationId !== null) {
            if ((int) ($m->to_location_id ?? 0) === $locationId) return 'in';
            if ((int) ($m->from_location_id ?? 0) === $locationId) return 'out';
        }
        if ($m->to_location_id !== null && $m->from_location_id === null) return 'in';
        if ($m->from_location_id !== null && $m->to_location_id === null) return 'out';
        // Unfiltered transfer between two locations: an internal move that nets
        // to zero at item level.
        if ($m->to_location_id !== null && $m->from_location_id !== null) return 'transfer';
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
