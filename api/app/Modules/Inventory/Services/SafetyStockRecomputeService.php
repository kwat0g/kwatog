<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\SettingsService;
use App\Modules\Inventory\Models\Item;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T1.4 — Recomputes items.safety_stock from recent issue history.
 *
 * Formula: SS = Z × σ_daily_demand × √lead_time_days
 *   - Z is configurable (95% default, Z=1.65)
 *   - σ uses sample standard deviation (n-1) over a daily-zero-filled window
 *   - issue movement set: material_issue, delivery, adjustment_out, scrap, return_to_vendor
 *
 * Pure entry point computeForItem() is testable; stddev() is exposed for
 * unit-level math pinning.
 */
class SafetyStockRecomputeService
{
    /** @var list<string> */
    private const ISSUE_TYPES = [
        'material_issue',
        'delivery',
        'adjustment_out',
        'scrap',
        'return_to_vendor',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Recompute every active, unlocked item. Returns counts.
     *
     * @return array{evaluated:int, updated:int, skipped:int}
     */
    public function recomputeAll(): array
    {
        if (! (bool) $this->settings->get('inventory.safety_stock.enabled', true)) {
            return ['evaluated' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $opts = $this->loadOpts();
        $evaluated = 0;
        $updated   = 0;
        $skipped   = 0;

        $items = Item::query()
            ->where('is_active', true)
            ->where('safety_stock_locked', false)
            ->where('lead_time_days', '>', 0)
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $evaluated++;
            try {
                $newSs = $this->computeForItem((int) $item->id, $opts);
                if ($newSs === null) {
                    $skipped++;
                    continue;
                }
                $item->forceFill([
                    'safety_stock'               => $newSs,
                    'safety_stock_recomputed_at' => now(),
                ])->saveQuietly();
                $updated++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('SafetyStockRecompute failed for item', [
                    'item_id' => $item->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return compact('evaluated', 'updated', 'skipped');
    }

    /**
     * @param array{z:float, history_days:int, min_demand_days:int} $opts
     */
    public function computeForItem(int $itemId, array $opts): ?float
    {
        $item = Item::query()->find($itemId);
        if (! $item || ! (bool) $item->is_active) return null;
        if ((bool) $item->safety_stock_locked) return null;
        if ((int) $item->lead_time_days <= 0) return null;

        $end   = Carbon::now()->endOfDay();
        $start = Carbon::now()->subDays($opts['history_days'] - 1)->startOfDay();

        $rows = DB::table('stock_movements')
            ->selectRaw('DATE(created_at) as d, SUM(quantity) as qty')
            ->where('item_id', $itemId)
            ->whereIn('movement_type', self::ISSUE_TYPES)
            ->whereBetween('created_at', [$start, $end])
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy('d');

        $series = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $series[] = isset($rows[$key]) ? (float) $rows[$key]->qty : 0.0;
            $cursor->addDay();
        }

        $nonZero = count(array_filter($series, fn ($v) => $v > 0));
        if ($nonZero < $opts['min_demand_days']) return null;

        $sigma = $this->stddev($series);
        $ss = $opts['z'] * $sigma * sqrt((int) $item->lead_time_days);
        return round($ss, 3);
    }

    /**
     * Sample standard deviation (n-1).
     *
     * @param  array<int, float> $values
     */
    public function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0.0;
        $mean = array_sum($values) / $n;
        $sumSq = 0.0;
        foreach ($values as $v) {
            $d = $v - $mean;
            $sumSq += $d * $d;
        }
        return sqrt($sumSq / ($n - 1));
    }

    /**
     * @return array{z:float, history_days:int, min_demand_days:int}
     */
    private function loadOpts(): array
    {
        return [
            'z'               => (float) $this->settings->get('inventory.safety_stock.service_level_z', 1.65),
            'history_days'    => (int)   $this->settings->get('inventory.safety_stock.history_days', 90),
            'min_demand_days' => (int)   $this->settings->get('inventory.safety_stock.min_demand_days', 14),
        ];
    }
}
