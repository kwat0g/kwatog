<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * ABC inventory classification based on annual usage value.
 *
 * Computes the total usage value (quantity × unit_cost) for each active item
 * over the trailing 12 months, then classifies:
 *   - A: items whose cumulative value accounts for the top 70% of total
 *   - B: next 20% of cumulative value
 *   - C: bottom 10% (or zero-usage items)
 */
class AbcClassificationService
{
    /**
     * Compute and persist ABC classifications for all active items.
     *
     * @return array{A: int, B: int, C: int}
     */
    public function compute(): array
    {
        return DB::transaction(function () {
            $cutoff = now()->subMonths(12)->startOfDay();

            // Fetch annual usage value per active item from outbound movements.
            // Outbound movement types that consume inventory value.
            $usageValues = DB::table('stock_movements')
                ->selectRaw('item_id, SUM(ABS(quantity) * unit_cost) as annual_value')
                ->whereIn('movement_type', [
                    'material_issue',
                    'delivery',
                    'adjustment_out',
                    'scrap',
                    'return_to_vendor',
                ])
                ->where('created_at', '>=', $cutoff)
                ->groupBy('item_id')
                ->get()
                ->keyBy('item_id');

            $items = Item::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id']);

            if ($items->isEmpty()) {
                return ['A' => 0, 'B' => 0, 'C' => 0];
            }

            // Build item-value map, zero for items with no movements.
            $itemValues = [];
            foreach ($items as $item) {
                $val = isset($usageValues[$item->id])
                    ? (float) $usageValues[$item->id]->annual_value
                    : 0.0;
                $itemValues[$item->id] = $val;
            }

            // Sort descending by value (highest usage first).
            arsort($itemValues, SORT_NUMERIC);

            $totalValue = array_sum($itemValues);
            if ($totalValue <= 0) {
                // No usage data — everything is C.
                $itemIds = $items->pluck('id')->toArray();
                $this->bulkUpdate($itemIds, 'C');
                return ['A' => 0, 'B' => 0, 'C' => $items->count()];
            }

            $runningCumulative = 0.0;
            $aIds = [];
            $bIds = [];
            $cIds = [];

            foreach ($itemValues as $id => $value) {
                $runningCumulative += $value;
                $cumPct = $runningCumulative / $totalValue;

                if ($cumPct <= 0.70) {
                    $aIds[] = $id;
                } elseif ($cumPct <= 0.90) {
                    $bIds[] = $id;
                } else {
                    $cIds[] = $id;
                }
            }

            $this->bulkUpdate($aIds, 'A');
            $this->bulkUpdate($bIds, 'B');
            $this->bulkUpdate($cIds, 'C');

            return [
                'A' => count($aIds),
                'B' => count($bIds),
                'C' => count($cIds),
            ];
        });
    }

    /**
     * Bulk-update abc_class for a set of item IDs.
     *
     * @param  list<int> $ids
     */
    private function bulkUpdate(array $ids, string $class): void
    {
        if (empty($ids)) return;

        DB::table('items')
            ->whereIn('id', $ids)
            ->update(['abc_class' => $class]);
    }
}
