<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Services;

use App\Common\Services\SettingsService;
use App\Modules\Forecasting\Enums\DemandSource;
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
 *        a) if a forecast exists for the configured forecast period → daily rate
 *        b) else → average over the configured demand-history window
 *           movements (negative quantity rows in stock_movements)
 *
 *   - days_until_stockout = max(0, (on_hand - safety_stock) / daily_demand)
 *
 * If daily_demand is 0 the item is considered "no risk" (returns null).
 * Items with `is_active = false` are skipped.
 */
class StockOutProjectionService
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<int, array<string, mixed>> */
    public function projectAll(?int $horizonDays = null): array
    {
        $horizonDays ??= $this->settings->requiredInt('inventory.stockout.default_horizon_days', 1);
        $now = Carbon::now();
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

        // 2) Average daily consumption over the configured history window.
        //    `material_issue` is negative; take the absolute and derive a daily rate.
        $historyDays = $this->settings->requiredInt('inventory.stockout.demand_history_days', 1);
        $forecastDays = $this->settings->requiredInt('inventory.stockout.forecast_period_days', 1);
        $coverageBuffer = number_format($this->settings->requiredFloat('inventory.stockout.coverage_buffer_ratio', 1), 6, '.', '');
        $zeroLeadRatio = number_format($this->settings->requiredFloat('inventory.stockout.zero_lead_reorder_ratio', 0), 6, '.', '');
        $highRiskBuffer = $this->settings->requiredInt('inventory.stockout.high_risk_buffer_days', 0);
        $mediumRiskDays = $this->settings->requiredInt('inventory.stockout.medium_risk_days', 1);
        $historyStart = $now->copy()->subDays($historyDays)->toDateTimeString();
        $consumption = DB::table('stock_movements')
            ->whereIn('movement_type', ['material_issue', 'consume', 'production_issue'])
            ->where('created_at', '>=', $historyStart)
            ->groupBy('item_id')
            ->select('item_id', DB::raw('SUM(ABS(quantity)) as consumed_30d'))
            ->pluck('consumed_30d', 'item_id');

        // 3) Forecasted demand for the *next* month (derived daily). Forecasts are scoped
        //    to PRODUCTS, but here we work with INVENTORY ITEMS, which are different
        //    domains. We use forecast as a soft hint when items.code matches a product
        //    part_number; otherwise we fall back to the configured historical average.
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
            $available = bcsub((string) $it->available, '0', 3);
            $safety = bcadd((string) ($it->safety_stock ?? '0.000'), '0', 3);
            $reorder = bcadd((string) ($it->reorder_point ?? '0.000'), '0', 3);
            $leadTime = (int) ($it->lead_time_days ?? 0);

            // Daily demand: forecast first, else configured moving average.
            $dailyDemand = '0.000000';
            $source = DemandSource::None->value;
            if (isset($forecastByPart[$it->code])) {
                $dailyDemand = bcdiv((string) $forecastByPart[$it->code], (string) $forecastDays, 6);
                $source = DemandSource::Forecast->value;
            } elseif (isset($consumption[$it->id]) && bccomp((string) $consumption[$it->id], '0', 3) > 0) {
                $dailyDemand = bcdiv((string) $consumption[$it->id], (string) $historyDays, 6);
                $source = DemandSource::Historical->value;
            }

            $daysUntilStockout = null;
            if (bccomp($dailyDemand, '0', 6) > 0) {
                $headroom = bcsub($available, $safety, 6);
                if (bccomp($headroom, '0', 6) < 0) {
                    $headroom = '0.000000';
                }
                $daysUntilStockout = (int) bcdiv($headroom, $dailyDemand, 0);
            }

            $reorderDate = null;
            $suggestedQty = null;
            if ($daysUntilStockout !== null) {
                // Order in time for lead time + 1 buffer day before depletion.
                $reorderDate = $now->copy()->addDays(max(0, $daysUntilStockout - $leadTime))->toDateString();
                // Suggested qty = MAX(MOQ, lead_time × daily_demand × configured safety buffer).
                $leadTimeQuantity = $leadTime > 0
                    ? bcmul(bcmul((string) $leadTime, $dailyDemand, 6), $coverageBuffer, 6)
                    : bcmul($reorder, $zeroLeadRatio, 6);
                $minimumOrderQuantity = bcadd((string) ($it->minimum_order_quantity ?? '0.000'), '0', 3);
                $suggestedQty = bccomp($minimumOrderQuantity, $leadTimeQuantity, 6) >= 0
                    ? $minimumOrderQuantity
                    : bcadd($leadTimeQuantity, '0.0005', 3);
            }

            $risk = 'ok';
            if ($daysUntilStockout !== null) {
                if ($daysUntilStockout <= $leadTime) {
                    $risk = 'critical';
                } elseif ($daysUntilStockout <= ($leadTime + $highRiskBuffer)) {
                    $risk = 'high';
                } elseif ($daysUntilStockout <= $mediumRiskDays) {
                    $risk = 'medium';
                } else {
                    $risk = 'low';
                }
            }

            // Only surface items within the horizon (or already breached).
            if ($daysUntilStockout !== null && $daysUntilStockout > $horizonDays) {
                continue;
            }

            $rows[] = [
                'item_id' => app('hashids')->encode((int) $it->id),
                'code' => $it->code,
                'name' => $it->name,
                'unit_of_measure' => $it->unit_of_measure,
                'available' => bcadd($available, '0', 3),
                'safety_stock' => $safety,
                'reorder_point' => $reorder,
                'lead_time_days' => $leadTime,
                'daily_demand' => bcadd($dailyDemand, '0', 3),
                'demand_source' => $source,
                'days_until_stockout' => $daysUntilStockout,
                'reorder_date' => $reorderDate,
                'suggested_qty' => $suggestedQty,
                'risk' => $risk,
            ];
        }

        // Sort by risk → days_until_stockout ascending so worst items surface first.
        $riskRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'ok' => 4];
        usort($rows, function ($a, $b) use ($riskRank) {
            $ra = $riskRank[$a['risk']] ?? 99;
            $rb = $riskRank[$b['risk']] ?? 99;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return ($a['days_until_stockout'] ?? PHP_INT_MAX) <=> ($b['days_until_stockout'] ?? PHP_INT_MAX);
        });

        return $rows;
    }
}
