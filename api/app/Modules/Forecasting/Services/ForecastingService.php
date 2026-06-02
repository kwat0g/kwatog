<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Forecasting\Models\DemandForecast;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * ADV11 — Demand & sales forecasting engine.
 *
 *   moving_avg    : simple average of the last N months of confirmed demand
 *   weighted_avg  : recency-weighted average (most recent month heaviest)
 *   manual        : operator-entered override
 *
 * Confirmed demand is read from `sales_order_items` joined to `sales_orders`,
 * grouped by year/month and (optionally) by customer. SOs in `cancelled` status
 * are excluded.
 */
class ForecastingService
{
    /**
     * Aggregate historical confirmed demand for a product, optionally per customer,
     * for the last N months ending at $endYear/$endMonth (inclusive).
     *
     * Returns rows shaped: ['year' => int, 'month' => int, 'qty' => float].
     */
    public function historicalDemand(
        int $productId,
        ?int $customerId,
        int $endYear,
        int $endMonth,
        int $monthsBack
    ): array {
        $end   = Carbon::create($endYear, $endMonth, 1)->endOfMonth();
        $start = Carbon::create($endYear, $endMonth, 1)->startOfMonth()->subMonthsNoOverflow($monthsBack - 1);

        $q = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->where('soi.product_id', $productId)
            ->whereNotIn('so.status', ['cancelled', 'draft'])
            ->whereBetween('so.date', [$start->toDateString(), $end->toDateString()]);

        if ($customerId !== null) {
            $q->where('so.customer_id', $customerId);
        }

        $rows = $q
            ->selectRaw('EXTRACT(YEAR FROM so.date)::int as y, EXTRACT(MONTH FROM so.date)::int as m, SUM(soi.quantity) as qty')
            ->groupByRaw('EXTRACT(YEAR FROM so.date), EXTRACT(MONTH FROM so.date)')
            ->orderByRaw('EXTRACT(YEAR FROM so.date), EXTRACT(MONTH FROM so.date)')
            ->get();

        // Build a complete series with zero-fill so MAs work correctly.
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[((int) $r->y) . '-' . ((int) $r->m)] = (float) $r->qty;
        }

        $series = [];
        $cursor = $start->copy();
        for ($i = 0; $i < $monthsBack; $i++) {
            $y = $cursor->year;
            $m = $cursor->month;
            $series[] = [
                'year'  => $y,
                'month' => $m,
                'qty'   => $byKey[$y . '-' . $m] ?? 0.0,
            ];
            $cursor->addMonthNoOverflow();
        }

        return $series;
    }

    /**
     * Compute a single forecast for ($productId, $customerId, $forecastYear, $forecastMonth)
     * using the requested method, persist it (upsert), and return the model.
     */
    public function compute(
        int $productId,
        ?int $customerId,
        int $forecastYear,
        int $forecastMonth,
        string $method,
        int $lookbackMonths = 6,
        ?User $user = null
    ): DemandForecast {
        if (! in_array($method, [DemandForecast::METHOD_MOVING_AVG, DemandForecast::METHOD_WEIGHTED_AVG], true)) {
            throw new InvalidArgumentException('compute() only supports moving_avg or weighted_avg; use storeManual() for manual.');
        }

        // Lookback ends the month BEFORE the forecast month.
        $endCursor = Carbon::create($forecastYear, $forecastMonth, 1)->subMonthNoOverflow();
        $series    = $this->historicalDemand($productId, $customerId, $endCursor->year, $endCursor->month, $lookbackMonths);

        [$qty, $confidence] = $this->applyMethod($series, $method);

        return DB::transaction(function () use (
            $productId, $customerId, $forecastYear, $forecastMonth, $method, $qty, $confidence, $user
        ) {
            $existing = DemandForecast::query()
                ->where('product_id', $productId)
                ->where('customer_id', $customerId)
                ->where('forecast_year', $forecastYear)
                ->where('forecast_month', $forecastMonth)
                ->first();

            $values = [
                'method'              => $method,
                'forecasted_quantity' => round($qty, 2),
                'confidence_level'    => $confidence !== null ? round($confidence, 2) : null,
            ];
            // Only stamp `created_by` on insert; preserve original author on update.
            if (! $existing) {
                $values['created_by'] = $user?->id;
            }

            return DemandForecast::updateOrCreate(
                [
                    'product_id'     => $productId,
                    'customer_id'    => $customerId,
                    'forecast_year'  => $forecastYear,
                    'forecast_month' => $forecastMonth,
                ],
                $values,
            )->fresh();
        });
    }

    /**
     * Recompute forecasts for the next $horizon months for all active products
     * (and by-customer if requested). Caller decides scope; caller passes an
     * optional callable invoked once per (product, customer) pair so the job
     * can checkpoint progress.
     *
     * @return int Number of forecast rows written.
     */
    public function recomputeBatch(
        Carbon $startMonth,
        int $horizonMonths,
        string $method,
        ?User $user = null,
        bool $perCustomer = false,
        int $lookbackMonths = 6
    ): int {
        $written = 0;

        $productIds = DB::table('products')->where('is_active', true)->pluck('id');

        foreach ($productIds as $pid) {
            $customerIds = $perCustomer
                ? DB::table('sales_orders')
                    ->whereNotNull('customer_id')
                    ->whereIn('id', function ($q) use ($pid) {
                        $q->from('sales_order_items')->select('sales_order_id')->where('product_id', $pid);
                    })->distinct()->pluck('customer_id')->all()
                : [null];
            // Always include the "all customers" total row so dashboards have a cross-customer view.
            if ($perCustomer && ! in_array(null, $customerIds, true)) {
                $customerIds[] = null;
            }

            foreach ($customerIds as $cid) {
                $cursor = $startMonth->copy();
                for ($i = 0; $i < $horizonMonths; $i++) {
                    $this->compute((int) $pid, $cid !== null ? (int) $cid : null, $cursor->year, $cursor->month, $method, $lookbackMonths, $user);
                    $written++;
                    $cursor->addMonthNoOverflow();
                }
            }
        }

        return $written;
    }

    /**
     * Manually set the forecast for one (product, customer, period).
     */
    public function storeManual(
        int $productId,
        ?int $customerId,
        int $forecastYear,
        int $forecastMonth,
        float $quantity,
        ?float $confidence = null,
        ?User $user = null
    ): DemandForecast {
        if ($quantity < 0) {
            throw new InvalidArgumentException('Manual forecast quantity must be ≥ 0.');
        }

        return DB::transaction(function () use (
            $productId, $customerId, $forecastYear, $forecastMonth, $quantity, $confidence, $user
        ) {
            $existing = DemandForecast::query()
                ->where('product_id', $productId)
                ->where('customer_id', $customerId)
                ->where('forecast_year', $forecastYear)
                ->where('forecast_month', $forecastMonth)
                ->first();

            $values = [
                'method'              => DemandForecast::METHOD_MANUAL,
                'forecasted_quantity' => round($quantity, 2),
                'confidence_level'    => $confidence !== null ? round($confidence, 2) : null,
            ];
            // Only stamp `created_by` on insert; preserve original author on update.
            if (! $existing) {
                $values['created_by'] = $user?->id;
            }

            return DemandForecast::updateOrCreate(
                [
                    'product_id'     => $productId,
                    'customer_id'    => $customerId,
                    'forecast_year'  => $forecastYear,
                    'forecast_month' => $forecastMonth,
                ],
                $values,
            )->fresh();
        });
    }

    /**
     * Backfill `actual_quantity` and `variance` for any forecasts whose
     * forecast period has fully elapsed. Idempotent.
     *
     * @return int Number of rows updated.
     */
    public function reconcileActuals(): int
    {
        $now = Carbon::now();

        // Only periods strictly before the current month qualify.
        $candidates = DemandForecast::query()
            ->whereNull('actual_quantity')
            ->where(function ($q) use ($now) {
                $q->where('forecast_year', '<', $now->year)
                    ->orWhere(function ($qq) use ($now) {
                        $qq->where('forecast_year', $now->year)
                            ->where('forecast_month', '<', $now->month);
                    });
            })
            ->get();

        $updated = 0;

        foreach ($candidates as $f) {
            $end = Carbon::create($f->forecast_year, $f->forecast_month, 1)->endOfMonth();
            $series = $this->historicalDemand(
                (int) $f->product_id,
                $f->customer_id !== null ? (int) $f->customer_id : null,
                $f->forecast_year,
                $f->forecast_month,
                1
            );
            $actual = isset($series[0]) ? (float) $series[0]['qty'] : 0.0;
            $variance = $actual - (float) $f->forecasted_quantity;

            $f->update([
                'actual_quantity' => round($actual, 2),
                'variance'        => round($variance, 2),
            ]);
            $updated++;
        }

        return $updated;
    }

    /**
     * @return array{0: float, 1: float|null}  [qty, confidence%]
     */
    private function applyMethod(array $series, string $method): array
    {
        if (count($series) === 0) {
            return [0.0, 0.0];
        }

        $values = array_map(fn ($r) => (float) $r['qty'], $series);

        if ($method === DemandForecast::METHOD_MOVING_AVG) {
            $qty = array_sum($values) / count($values);
            return [$qty, $this->confidenceFromSeries($values, $qty)];
        }

        if ($method === DemandForecast::METHOD_WEIGHTED_AVG) {
            // Weights are 1, 2, 3, ..., N from oldest to newest (linear recency weighting).
            $weights = range(1, count($values));
            $sumW    = array_sum($weights);
            $qty     = 0.0;
            foreach ($values as $i => $v) {
                $qty += $v * $weights[$i] / $sumW;
            }
            return [$qty, $this->confidenceFromSeries($values, $qty)];
        }

        throw new RuntimeException("Unsupported method: {$method}");
    }

    /**
     * Confidence% = clamp(100 - 100 × CV, 0, 100), where CV = stddev / mean.
     * If mean is 0 we report 50% (neutral) since no signal exists.
     */
    private function confidenceFromSeries(array $values, float $forecastQty): ?float
    {
        $n = count($values);
        if ($n < 2) return null;

        $mean = array_sum($values) / $n;
        if ($mean <= 0) return 50.0;

        $variance = 0.0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $stddev = sqrt($variance / $n);
        $cv     = $stddev / $mean;
        $conf   = 100.0 - (100.0 * $cv);
        return max(0.0, min(100.0, $conf));
    }
}
