<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
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
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Recompute every active, unlocked item. Returns counts.
     *
     * @return array{evaluated:int, updated:int, skipped:int}
     */
    public function recomputeAll(): array
    {
        $enabled = $this->settings->get('inventory.safety_stock.enabled');
        if (! is_bool($enabled)) {
            throw new BusinessRuleException('Required business setting inventory.safety_stock.enabled is missing or invalid.');
        }
        if (! $enabled) {
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
     * @param array{z:float, history_days:int, min_demand_days:int, issue_movement_types:list<string>} $opts
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
            ->whereIn('movement_type', $opts['issue_movement_types'])
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
     * @return array{z:float, history_days:int, min_demand_days:int, issue_movement_types:list<string>}
     */
    private function loadOpts(): array
    {
        $z = $this->settings->get('inventory.safety_stock.service_level_z');
        $historyDays = $this->settings->get('inventory.safety_stock.history_days');
        $minDemandDays = $this->settings->get('inventory.safety_stock.min_demand_days');
        $issueMovementTypes = $this->settings->get('inventory.safety_stock.issue_movement_types');
        if (! is_numeric($z) || (float) $z <= 0) {
            throw new BusinessRuleException('Required business setting inventory.safety_stock.service_level_z is missing or invalid.');
        }
        if (! is_numeric($historyDays) || (int) $historyDays <= 0) {
            throw new BusinessRuleException('Required business setting inventory.safety_stock.history_days is missing or invalid.');
        }
        if (! is_numeric($minDemandDays) || (int) $minDemandDays <= 0) {
            throw new BusinessRuleException('Required business setting inventory.safety_stock.min_demand_days is missing or invalid.');
        }
        if (! is_array($issueMovementTypes) || $issueMovementTypes === []) {
            throw new BusinessRuleException('Required business setting inventory.safety_stock.issue_movement_types is missing or invalid.');
        }
        $issueMovementTypes = array_values(array_filter($issueMovementTypes, static fn ($type): bool => is_string($type) && trim($type) !== ''));
        if ($issueMovementTypes === []) {
            throw new BusinessRuleException('Required business setting inventory.safety_stock.issue_movement_types is missing or invalid.');
        }

        return ['z' => (float) $z, 'history_days' => (int) $historyDays, 'min_demand_days' => (int) $minDemandDays, 'issue_movement_types' => $issueMovementTypes];
    }
}
