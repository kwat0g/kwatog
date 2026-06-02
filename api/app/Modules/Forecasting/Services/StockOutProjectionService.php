<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADV11 — Stock-out projection.
 *
 * For each active inventory item, project the number of days remaining until
 * stock falls below the safety_stock threshold. Inputs:
 *
 *   - on_hand   : sum(stock_levels.quantity - stock_levels.reserved_quantity)
 *   - daily_demand :
 *        a) if a forecast exists for the next month → forecasted_qty / 30
 *        b) else → average of last 30 days of `material_issue` / `consume`
 *           movements (negative quantity rows in stock_movements)
 *
 *   - days_until_stockout = max(0, (on_hand - safety_stock) / daily_demand)
 *
 * If daily_demand is 0 the item is considered "no risk" (returns null).
 * Items with `is_active = false` are skipped.
 */
class StockOutProjectionService
{
    /** @return array<int, array<string, mixed>> */
    public function projectAll(int $horizonDays = 60): array
    {
        $now    = Carbon::now();
        $monthY = $now->year;
        $monthM = $now->month;

        // 1) Pull all active items with their on-hand/safety/reorder levels and lead time.
        $items = DB::table('items')
            ->leftJoin('stock_levels', 'stock_levels.item_id', '=', 'items.id')
            ->where('items.is_active', true)
            ->groupBy(
                'items.id', 'items.code', 'items.name', 'items.unit_of_measure',
                'items.safety_stock', 'items.reorder_point', 'items.minimum_order_quantity',
                'items.lead_time_days'
            )
            ->select(
                'items.id', 'items.code', 'items.name', 'items.unit_of_measure',
                'items.safety_stock', 'items.reorder_point', 'items.minimum_order_quantity',
                'items.lead_time_days',
                DB::raw('COALESCE(SUM(stock_levels.quantity - stock_levels.reserved_quantity), 0) as available')
            )
            ->get();

        if ($items->isEmpty()) {
            return [];
        }

        // 2) Average daily consumption over the last 30 days, derived from issue/consume movements.
        //    `material_issue` is negative; we take the absolute and divide by 30 to get a daily rate.
        $thirtyDaysAgo = $now->copy()->subDays(30)->toDateTimeString();
        $consumption = DB::table('stock_movements')
            ->whereIn('movement_type', ['material_issue', 'consume', 'production_issue'])
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->groupBy('item_id')
            ->select('item_id', DB::raw('SUM(ABS(quantity)) as consumed_30d'))
            ->pluck('consumed_30d', 'item_id');

        // 3) Forecasted demand for the *next* month (derived daily). Forecasts are scoped
        //    to PRODUCTS, but here we work with INVENTORY ITEMS, which are different
        //    domains. We use forecast as a soft hint when items.code matches a product
        //    part_number; otherwise we fall back to the 30-day average.
        $next = $now->copy()->addMonthNoOverflow();
        $forecastByPart = DB::table('demand_forecasts as f')
            ->join('products as p', 'p.id', '=', 'f.product_id')
            ->whereNull('f.customer_id')        // total forecast across customers
            ->where('f.forecast_year', $next->year)
            ->where('f.forecast_month', $next->month)
            ->select('p.part_number', DB::raw('SUM(f.forecasted_quantity) as qty'))
            ->groupBy('p.part_number')
            ->pluck('qty', 'part_number');

        $rows = [];
        foreach ($items as $it) {
            $available = (float) $it->available;
            $safety    = (float) $it->safety_stock;
            $reorder   = (float) $it->reorder_point;
            $leadTime  = (int) ($it->lead_time_days ?? 0);

            // Daily demand: forecast first, else 30-day moving average.
            $dailyDemand = 0.0;
            $source      = 'none';
            if (isset($forecastByPart[$it->code])) {
                $dailyDemand = (float) $forecastByPart[$it->code] / 30.0;
                $source      = 'forecast';
            } elseif (isset($consumption[$it->id]) && (float) $consumption[$it->id] > 0) {
                $dailyDemand = (float) $consumption[$it->id] / 30.0;
                $source      = 'historical';
            }

            $daysUntilStockout = null;
            if ($dailyDemand > 0) {
                $headroom = max(0.0, $available - $safety);
                $daysUntilStockout = (int) floor($headroom / $dailyDemand);
            }

            $reorderDate = null;
            $suggestedQty = null;
            if ($daysUntilStockout !== null) {
                // Order in time for lead time + 1 buffer day before depletion.
                $reorderDate = $now->copy()->addDays(max(0, $daysUntilStockout - $leadTime))->toDateString();
                // Suggested qty = MAX(MOQ, lead_time × daily_demand × 1.2 safety buffer).
                $suggested   = max(
                    (float) $it->minimum_order_quantity,
                    $leadTime > 0 ? ($leadTime * $dailyDemand * 1.2) : ($reorder * 0.5)
                );
                $suggestedQty = round($suggested, 3);
            }

            $risk = 'ok';
            if ($daysUntilStockout !== null) {
                if ($daysUntilStockout <= $leadTime)        $risk = 'critical';
                elseif ($daysUntilStockout <= ($leadTime + 7)) $risk = 'high';
                elseif ($daysUntilStockout <= 30)             $risk = 'medium';
                else                                          $risk = 'low';
            }

            // Only surface items within the horizon (or already breached).
            if ($daysUntilStockout !== null && $daysUntilStockout > $horizonDays) {
                continue;
            }

            $rows[] = [
                'item_id'             => (int) $it->id,
                'code'                => $it->code,
                'name'                => $it->name,
                'unit_of_measure'     => $it->unit_of_measure,
                'available'           => round($available, 3),
                'safety_stock'        => round($safety, 3),
                'reorder_point'       => round($reorder, 3),
                'lead_time_days'      => $leadTime,
                'daily_demand'        => round($dailyDemand, 3),
                'demand_source'       => $source,
                'days_until_stockout' => $daysUntilStockout,
                'reorder_date'        => $reorderDate,
                'suggested_qty'       => $suggestedQty,
                'risk'                => $risk,
            ];
        }

        // Sort by risk → days_until_stockout ascending so worst items surface first.
        $riskRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'ok' => 4];
        usort($rows, function ($a, $b) use ($riskRank) {
            $ra = $riskRank[$a['risk']] ?? 99;
            $rb = $riskRank[$b['risk']] ?? 99;
            if ($ra !== $rb) return $ra <=> $rb;
            return ($a['days_until_stockout'] ?? PHP_INT_MAX) <=> ($b['days_until_stockout'] ?? PHP_INT_MAX);
        });

        return $rows;
    }
}
