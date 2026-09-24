<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Services;

use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\MRP\Exceptions\MissingBomException;
use App\Modules\MRP\Services\BomService;

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
        $forecastsByProduct = DemandForecast::query()
            ->with('product:id,name')
            ->whereHas('product', fn ($query) => $query
                ->where('is_active', true)
                ->where('include_forecast_in_mrp', true))
            ->where('forecast_year', $year)
            ->where('forecast_month', $month)
            ->where('forecasted_quantity', '>', 0)
            ->get()
            ->groupBy('product_id');

        $grossPerItem = [];   // item_id => decimal gross requirement
        $products = [];

        foreach ($forecastsByProduct as $rows) {
            // The NULL-customer row is the cross-customer total; when present it
            // is authoritative on its own. Summing it together with the
            // per-customer rows would double-count demand (FC-01). Only fall
            // back to the per-customer rows when no total row exists.
            $total = $rows->first(fn ($fc) => $fc->customer_id === null);
            if ($total !== null) {
                $rows = collect([$total]);
            }

            // Decimal-safe accumulation: quantities arrive as decimal strings
            // (decimal(12,2)) and float addition drifts — 0.10 + 0.20 becomes
            // 0.30000000000000004, which the BOM explosion then multiplies.
            $qty = '0.00';
            foreach ($rows as $fc) {
                $qty = bcadd($qty, (string) $fc->forecasted_quantity, 2);
            }
            // BomService currently accepts a float at the MRP module boundary;
            // keep all Forecasting accumulation and report values decimal-safe.
            $finishedQuantity = (float) $qty;

            $product = $rows->first()->product;
            $hasBom = false;
            try {
                $exploded = $this->bom->explode((int) $rows->first()->product_id, $finishedQuantity);
                $hasBom = $exploded->isNotEmpty();
                foreach ($exploded as $row) {
                    $iid = (int) $row['item_id'];
                    $grossPerItem[$iid] = bcadd(
                        $grossPerItem[$iid] ?? '0.000',
                        (string) $row['gross_quantity'],
                        3,
                    );
                }
            } catch (MissingBomException $e) {
                // No active BOM — product is flagged has_bom=false in the report.
            }

            $products[] = [
                'product_id'          => $product?->hash_id,
                'product_name'        => $product?->name,
                'forecasted_quantity' => bcadd($qty, '0', 2),
                'has_bom'             => $hasBom,
            ];
        }

        $materials = $this->netRequirements($grossPerItem);
        $shortages = array_values(array_filter($materials, fn ($m) => bccomp((string) $m['net_shortage'], '0', 3) > 0));

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
     * @param array<int, string> $grossPerItem
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

            $onHand = (string) ($stock->get($itemId)->on_hand ?? '0.000');
            $reserved = (string) ($stock->get($itemId)->reserved ?? '0.000');
            $safety = (string) ($item->safety_stock ?? '0.000');
            $available = bcsub($onHand, $reserved, 3);
            if (bccomp($available, '0', 3) < 0) {
                $available = '0.000';
            }

            // Net shortage = gross + safety buffer - available.
            $netShortage = bcsub(bcadd((string) $gross, $safety, 3), $available, 3);
            if (bccomp($netShortage, '0', 3) < 0) {
                $netShortage = '0.000';
            }

            $out[] = [
                'item_id'        => $item->hash_id,
                'item_code'      => $item->code,
                'item_name'      => $item->name,
                'gross_required' => bcadd((string) $gross, '0', 3),
                'on_hand'        => bcadd($onHand, '0', 3),
                'safety_stock'   => bcadd($safety, '0', 3),
                'net_shortage'   => bcadd($netShortage, '0', 3),
                'lead_time_days' => (int) ($item->lead_time_days ?? 0),
            ];
        }

        // Surface biggest shortages first.
        usort($out, fn ($a, $b) => bccomp((string) $b['net_shortage'], (string) $a['net_shortage'], 3));

        return $out;
    }
}
