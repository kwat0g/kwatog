<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Services;

use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\MRP\Services\BomService;
use Illuminate\Support\Collection;

/**
 * Forecast-driven MRP projection (ADV11 → MRP bridge).
 *
 * Where the live MRP engine nets demand from CONFIRMED sales orders, this
 * projects FORWARD material requirements from demand forecasts: explode each
 * forecasted finished good through its BOM, aggregate the raw-material gross
 * requirement, net against current on-hand (less reserved), and surface the
 * projected shortage per material for a target forecast month.
 *
 * Advisory only — it produces a planning report, it does not create PRs (that
 * remains the confirmed-SO MRP engine's job). This lets planners pre-empt
 * long-lead-time material shortages before the orders are even placed.
 */
class ForecastMrpService
{
    public function __construct(private readonly BomService $bom) {}

    /**
     * Project net material requirements for a forecast month.
     *
     * @return array{
     *   period: array{year:int, month:int},
     *   products: array<int, array{product_id:string, product_name:string, forecasted_quantity:string, has_bom:bool}>,
     *   materials: array<int, array{item_id:string, item_code:string, item_name:string, gross_required:string, on_hand:string, safety_stock:string, net_shortage:string, lead_time_days:int}>,
     *   shortage_count: int
     * }
     */
    public function project(int $year, int $month): array
    {
        $forecasts = DemandForecast::query()
            ->with('product:id,name')
            ->where('forecast_year', $year)
            ->where('forecast_month', $month)
            ->where('forecasted_quantity', '>', 0)
            ->get();

        $grossPerItem = [];   // item_id => float gross requirement
        $products = [];

        foreach ($forecasts as $fc) {
            $qty = (float) $fc->forecasted_quantity;
            $hasBom = false;
            try {
                $exploded = $this->bom->explode((int) $fc->product_id, $qty);
                $hasBom = $exploded->isNotEmpty();
                foreach ($exploded as $row) {
                    $iid = (int) $row['item_id'];
                    $grossPerItem[$iid] = ($grossPerItem[$iid] ?? 0.0) + (float) $row['gross_quantity'];
                }
            } catch (\Throwable $e) {
                // No active BOM — product is flagged has_bom=false in the report.
            }

            $products[] = [
                'product_id'          => $fc->product?->hash_id,
                'product_name'        => $fc->product?->name,
                'forecasted_quantity' => number_format($qty, 2, '.', ''),
                'has_bom'             => $hasBom,
            ];
        }

        $materials = $this->netRequirements($grossPerItem);
        $shortages = array_values(array_filter($materials, fn ($m) => (float) $m['net_shortage'] > 0));

        return [
            'period'         => ['year' => $year, 'month' => $month],
            'products'       => $products,
            'materials'      => $materials,
            'shortage_count' => count($shortages),
        ];
    }

    /**
     * Net gross requirements against current on-hand (less reserved) + safety stock.
     *
     * @param array<int, float> $grossPerItem
     * @return array<int, array<string, mixed>>
     */
    private function netRequirements(array $grossPerItem): array
    {
        if (empty($grossPerItem)) {
            return [];
        }

        $items = Item::query()
            ->whereIn('id', array_keys($grossPerItem))
            ->get(['id', 'code', 'name', 'safety_stock', 'lead_time_days'])
            ->keyBy('id');

        // On-hand and reserved across all locations, per item.
        $stock = StockLevel::query()
            ->whereIn('item_id', array_keys($grossPerItem))
            ->selectRaw('item_id, COALESCE(SUM(quantity),0) as on_hand, COALESCE(SUM(reserved_quantity),0) as reserved')
            ->groupBy('item_id')
            ->get()
            ->keyBy('item_id');

        $out = [];
        foreach ($grossPerItem as $itemId => $gross) {
            $item = $items->get($itemId);
            if (! $item) continue;

            $onHand   = (float) ($stock->get($itemId)->on_hand ?? 0);
            $reserved = (float) ($stock->get($itemId)->reserved ?? 0);
            $safety   = (float) $item->safety_stock;
            $available = max(0.0, $onHand - $reserved);

            // Net shortage = gross + safety buffer - available.
            $netShortage = max(0.0, $gross + $safety - $available);

            $out[] = [
                'item_id'        => $item->hash_id,
                'item_code'      => $item->code,
                'item_name'      => $item->name,
                'gross_required' => number_format($gross, 3, '.', ''),
                'on_hand'        => number_format($onHand, 3, '.', ''),
                'safety_stock'   => number_format($safety, 3, '.', ''),
                'net_shortage'   => number_format($netShortage, 3, '.', ''),
                'lead_time_days' => (int) ($item->lead_time_days ?? 0),
            ];
        }

        // Surface biggest shortages first.
        usort($out, fn ($a, $b) => (float) $b['net_shortage'] <=> (float) $a['net_shortage']);

        return $out;
    }
}
