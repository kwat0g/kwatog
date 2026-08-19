<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\SettingsService;
use App\Common\Services\OutboxService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Events\SupplierPerformanceComputed;
use App\Modules\Purchasing\Models\SupplierPerformanceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Series F — Task F4 / ADV7. Supplier performance computation.
 *
 * Metrics (computed per (vendor, year, month)):
 *
 *   - on_time_delivery_rate  = POs received on/before expected_delivery_date
 *                              ÷ POs received that month × 100
 *   - quality_pass_rate      = Incoming QC inspections passed ÷ total incoming inspections × 100
 *                              (falls back to GRN status when no QC data)
 *   - ncr_rate               = NCRs linked to this vendor's GRNs ÷ total GRNs × 100
 *                              (lower is better; inverted in scoring)
 *   - price_variance_pct     = avg |actual_unit_cost - po_unit_price| / po_unit_price × 100
 *                              (approximated by receipt shortfall when per-line costs absent)
 *   - lead_time_variance_days = avg(actual_lead_days - quoted_lead_days)
 *                               where quoted = first approved_supplier.lead_time_days for the item
 *   - overall_score          = weighted avg:
 *                              25% on-time + 35% quality + 10% NCR rate + 15% price + 15% lead_time
 *                              (each scored 0–100; lower-is-better metrics inverted)
 */
class SupplierPerformanceService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function compute(Vendor $vendor, int $year, int $month): SupplierPerformanceSnapshot
    {
        $snapshot = DB::transaction(function () use ($vendor, $year, $month) {
            $start = Carbon::create($year, $month, 1)->startOfDay();
            $end   = $start->copy()->endOfMonth()->endOfDay();

            $onTime    = $this->onTimeDeliveryRate($vendor->id, $start, $end);
            $qcMetrics = $this->qualityMetrics($vendor->id, $start, $end);
            $quality   = $qcMetrics['passRate'];
            $qcBreakdown = $qcMetrics['breakdown'];
            $ncrRate   = $this->ncrRate($vendor->id, $start, $end);
            $price     = $this->priceVariancePct($vendor->id, $start, $end);
            $leadTime  = $this->leadTimeVarianceDays($vendor->id, $start, $end);

            $poCount  = (int) DB::table('purchase_orders')
                ->where('vendor_id', $vendor->id)
                ->whereBetween('date', [$start, $end])
                ->count();
            $grnCount = (int) DB::table('goods_receipt_notes')
                ->where('vendor_id', $vendor->id)
                ->whereBetween('received_date', [$start, $end])
                ->count();

            $overall = $this->compositeScore($onTime, $quality, $ncrRate, $price, $leadTime);
            $tier    = $this->tierFromScore($overall);

            $snapshot = SupplierPerformanceSnapshot::updateOrCreate(
                [
                    'vendor_id'    => $vendor->id,
                    'period_year'  => $year,
                    'period_month' => $month,
                ],
                [
                    'on_time_delivery_rate'   => $onTime,
                    'quality_pass_rate'       => $quality,
                    'incoming_quality_rate'   => $qcBreakdown['incoming'] ?? null,
                    'in_process_quality_rate' => $qcBreakdown['in_process'] ?? null,
                    'outgoing_quality_rate'   => $qcBreakdown['outgoing'] ?? null,
                    'ncr_rate'                => $ncrRate,
                    'price_variance_pct'      => $price,
                    'lead_time_variance_days' => $leadTime,
                    'overall_score'           => $overall,
                    'tier'                    => $tier,
                    'po_count'                => $poCount,
                    'grn_count'               => $grnCount,
                    'computed_at'             => now(),
                ],
            );
            app(OutboxService::class)->record(new SupplierPerformanceComputed($snapshot));

            return $snapshot;
        });

        return $snapshot;
    }

    /**
     * T3.3.A — Map overall_score to a tier letter.
     *
     * Boundaries (inclusive lower bounds): A >= 90, B >= 75, C >= 60, D < 60.
     * NULL score → NULL tier (vendors with no data don't get a synthetic letter).
     */
    private function tierFromScore(?float $score): ?string
    {
        if ($score === null) return null;
        $a = $this->setting('purchasing.supplier_score.tier_a_min');
        $b = $this->setting('purchasing.supplier_score.tier_b_min');
        $c = $this->setting('purchasing.supplier_score.tier_c_min');
        if (! ($a > $b && $b > $c)) {
            throw new \App\Common\Exceptions\BusinessRuleException('Supplier tier thresholds must be strictly descending.');
        }
        if ($score >= $a) return 'A';
        if ($score >= $b) return 'B';
        if ($score >= $c) return 'C';
        return 'D';
    }

    /**
     * @return Collection<int, SupplierPerformanceSnapshot>
     */
    public function trendForVendor(Vendor $vendor, ?int $months = null): Collection
    {
        $months ??= $this->settingInt('purchasing.supplier_score.trend_months', 1, 36);
        $cutoff = Carbon::now()->subMonths($months - 1)->startOfMonth();
        return SupplierPerformanceSnapshot::query()
            ->where('vendor_id', $vendor->id)
            ->where(function ($q) use ($cutoff) {
                $q->where('period_year', '>', $cutoff->year)
                  ->orWhere(function ($q2) use ($cutoff) {
                      $q2->where('period_year', $cutoff->year)
                         ->where('period_month', '>=', $cutoff->month);
                  });
            })
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get();
    }

    /**
     * Recompute snapshots for every vendor for the given month.
     *
     * Vendors are isolated so one malformed source record cannot prevent the
     * remaining vendors from being processed. The returned failure list keeps
     * the command non-green when the month is only partially complete.
     *
     * @return array{computed:int,failed:array<int,array{vendor_id:int,error:string}>}
     */
    public function recomputeAll(int $year, int $month): array
    {
        $count = 0;
        $failed = [];
        Vendor::query()->orderBy('id')->chunk(100, function ($vendors) use (&$count, &$failed, $year, $month) {
            foreach ($vendors as $vendor) {
                try {
                    $this->compute($vendor, $year, $month);
                    $count++;
                } catch (\Throwable $e) {
                    // The failure collection is captured by reference below;
                    // continue so unrelated vendors still receive a snapshot.
                    $failed[] = [
                        'vendor_id' => (int) $vendor->id,
                        'error' => $e->getMessage(),
                    ];
                    Log::error('Supplier performance recompute failed for vendor.', [
                        'vendor_id' => $vendor->id,
                        'year' => $year,
                        'month' => $month,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return ['computed' => $count, 'failed' => $failed];
    }

    /**
     * T3.3.B — Cross-vendor ranking for a given period.
     *
     * Returns supplier_performance_snapshots rows joined to their vendor,
     * ordered by overall_score desc. Optional tier filter (A|B|C|D) and
     * server-side limit (clamped to 100).
     *
     * @return Collection<int, SupplierPerformanceSnapshot>
     */
    public function ranking(int $year, int $month, ?string $tier = null, int $limit = 50): Collection
    {
        $clampedLimit = max(1, min($limit, 100));

        $q = SupplierPerformanceSnapshot::query()
            ->with('vendor:id,name')
            ->where('period_year', $year)
            ->where('period_month', $month);

        if ($tier !== null && in_array($tier, ['A', 'B', 'C', 'D'], true)) {
            $q->where('tier', $tier);
        }

        return $q->orderByDesc('overall_score')
            ->orderBy('vendor_id')
            ->limit($clampedLimit)
            ->get();
    }

    private function onTimeDeliveryRate(int $vendorId, Carbon $start, Carbon $end): ?float
    {
        $rows = DB::table('goods_receipt_notes as g')
            ->leftJoin('purchase_orders as po', 'g.purchase_order_id', '=', 'po.id')
            ->select(['g.received_date', 'po.expected_delivery_date'])
            ->where('g.vendor_id', $vendorId)
            ->whereBetween('g.received_date', [$start, $end])
            ->get();

        if ($rows->isEmpty()) return null;

        $onTime = 0;
        $total = 0;
        foreach ($rows as $r) {
            if ($r->expected_delivery_date === null) continue;
            $total++;
            if (Carbon::parse((string) $r->received_date)->lte(Carbon::parse((string) $r->expected_delivery_date))) {
                $onTime++;
            }
        }

        return $total > 0 ? round(($onTime / $total) * 100, 2) : null;
    }

    /**
     * ADV7 — Single-query quality metrics: overall pass rate + per-stage breakdown.
     *
     * Queries incoming QC inspections linked to this vendor's GRNs.
     * Falls back to GRN status when no QC inspection exists.
     */
    private function qualityMetrics(int $vendorId, Carbon $start, Carbon $end): array
    {
        $breakdown = ['incoming' => null, 'in_process' => null, 'outgoing' => null];

        // Single query: all GRN-linked inspections for this vendor in period.
        $rows = DB::table('goods_receipt_notes as grn')
            ->join('inspections as i', function ($join) {
                $join->on('i.entity_id', '=', 'grn.id')
                     ->where('i.entity_type', '=', 'grn');
            })
            ->select(['i.stage', 'i.status'])
            ->where('grn.vendor_id', $vendorId)
            ->whereBetween('grn.received_date', [$start, $end])
            ->get();

        if ($rows->isNotEmpty()) {
            // P3.3 fix: restrict DENOMINATOR to terminal statuses only so
            // open (draft / in_progress) inspections do not dilute the score.
            $terminal = $rows->whereIn('status', ['passed', 'failed']);
            $terminalCount = $terminal->count();
            $passed = $terminal->where('status', 'passed')->count();
            $passRate = $terminalCount > 0
                ? round(($passed / $terminalCount) * 100, 2)
                : null;

            $byStage = $rows->groupBy('stage');
            foreach (['incoming', 'in_process', 'outgoing'] as $stage) {
                $stageRows = $byStage->get($stage);
                if ($stageRows && $stageRows->isNotEmpty()) {
                    $stageTerminal = $stageRows->whereIn('status', ['passed', 'failed']);
                    $stageTerminalCount = $stageTerminal->count();
                    if ($stageTerminalCount > 0) {
                        $stagePassed = $stageTerminal->where('status', 'passed')->count();
                        $breakdown[$stage] = round(($stagePassed / $stageTerminalCount) * 100, 2);
                    }
                }
            }

            return ['passRate' => $passRate, 'breakdown' => $breakdown];
        }

        // Fallback: use GRN status for overall pass rate only.
        $grnRows = DB::table('goods_receipt_notes')
            ->select(['status'])
            ->where('vendor_id', $vendorId)
            ->whereBetween('received_date', [$start, $end])
            ->get();

        if ($grnRows->isEmpty()) {
            return ['passRate' => null, 'breakdown' => $breakdown];
        }

        $accepted = $grnRows->where('status', 'accepted')->count();
        return [
            'passRate'  => round(($accepted / $grnRows->count()) * 100, 2),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * ADV7 — NCR rate: NCRs linked to this vendor's GRNs ÷ total GRNs × 100.
     * Lower is better. Uses NCRs sourced from inspection_fail that are linked
     * to inspections which are in turn linked to this vendor's GRNs.
     */
    private function ncrRate(int $vendorId, Carbon $start, Carbon $end): ?float
    {
        $totalGrns = (int) DB::table('goods_receipt_notes')
            ->where('vendor_id', $vendorId)
            ->whereBetween('received_date', [$start, $end])
            ->count();

        if ($totalGrns === 0) return null;

        $ncrCount = (int) DB::table('non_conformance_reports as ncr')
            ->join('inspections as i', 'ncr.inspection_id', '=', 'i.id')
            ->join('goods_receipt_notes as grn', function ($join) {
                $join->on('i.entity_id', '=', 'grn.id')
                     ->where('i.entity_type', '=', 'grn');
            })
            ->where('grn.vendor_id', $vendorId)
            ->whereBetween('grn.received_date', [$start, $end])
            ->where('ncr.source', 'inspection_fail')
            ->count();

        return round(($ncrCount / $totalGrns) * 100, 2);
    }

    private function priceVariancePct(int $vendorId, Carbon $start, Carbon $end): ?float
    {
        $rows = DB::table('purchase_orders as po')
            ->join('purchase_order_items as poi', 'poi.purchase_order_id', '=', 'po.id')
            ->select([
                DB::raw('SUM(poi.quantity) as qty'),
                DB::raw('SUM(poi.quantity_received) as recv'),
            ])
            ->where('po.vendor_id', $vendorId)
            ->whereBetween('po.date', [$start, $end])
            ->first();

        if (! $rows || (float) ($rows->qty ?? 0) <= 0) return null;

        $shortfall = max(0, (float) $rows->qty - (float) ($rows->recv ?? 0));
        $pct = ($shortfall / (float) $rows->qty) * 100;
        return round($pct, 2);
    }

    private function leadTimeVarianceDays(int $vendorId, Carbon $start, Carbon $end): ?float
    {
        $rows = DB::table('goods_receipt_notes as g')
            ->leftJoin('purchase_orders as po', 'g.purchase_order_id', '=', 'po.id')
            ->select(['g.received_date', 'po.date as po_date', 'po.expected_delivery_date'])
            ->where('g.vendor_id', $vendorId)
            ->whereBetween('g.received_date', [$start, $end])
            ->get();

        if ($rows->isEmpty()) return null;

        $diffs = [];
        foreach ($rows as $r) {
            if (! $r->po_date || ! $r->expected_delivery_date) continue;
            // Both diffs are SIGNED offsets from the same anchor (po_date), and
            // it is their DIFFERENCE that is the variance — so the signs must
            // survive. Neither date is guaranteed to follow the PO date:
            // UpdatePurchaseOrderRequest accepts any `expected_delivery_date`
            // and StoreGrnRequest accepts any `received_date`. Taking magnitudes
            // would fold a backdated expectation the wrong way and understate
            // lateness by twice the backdating.
            $expected = Carbon::parse((string) $r->po_date)->diffInDays(Carbon::parse((string) $r->expected_delivery_date), false);
            $actual   = Carbon::parse((string) $r->po_date)->diffInDays(Carbon::parse((string) $r->received_date), false);
            $diffs[] = $actual - $expected;
        }

        if (empty($diffs)) return null;
        $avg = array_sum($diffs) / count($diffs);
        return round($avg, 2);
    }

    /**
     * ADV7 — Updated composite score with NCR rate weighting.
     *
     * Weights: 25% on-time + 35% quality + 10% NCR rate + 15% price + 15% lead_time
     */
    private function compositeScore(
        ?float $onTime,
        ?float $quality,
        ?float $ncrRate,
        ?float $price,
        ?float $leadTime,
    ): ?float {
        if ($onTime === null && $quality === null) {
            return null;
        }

        // Score each on 0–100 (higher is better).
        $onTimeScore   = $onTime ?? 0;                                   // already 0-100
        $qualityScore  = $quality ?? 0;                                  // already 0-100
        $neutral = $this->setting('purchasing.supplier_score.neutral_missing_metric');
        $ncrScore      = $ncrRate === null ? $neutral : max(0, 100 - $ncrRate * $this->setting('purchasing.supplier_score.ncr_penalty_factor'));
        $priceScore    = $price === null ? $neutral : max(0, 100 - $price * $this->setting('purchasing.supplier_score.price_penalty_factor'));
        $leadTimeScore = $leadTime === null ? $neutral : max(0, 100 - abs($leadTime) * $this->setting('purchasing.supplier_score.lead_time_penalty_factor'));

        $weights = [
            $this->setting('purchasing.supplier_score.weight_on_time'),
            $this->setting('purchasing.supplier_score.weight_quality'),
            $this->setting('purchasing.supplier_score.weight_ncr'),
            $this->setting('purchasing.supplier_score.weight_price'),
            $this->setting('purchasing.supplier_score.weight_lead_time'),
        ];
        if (abs(array_sum($weights) - 1.0) > 0.0001) {
            throw new \App\Common\Exceptions\BusinessRuleException('Supplier score weights must total 1.0.');
        }
        $score = ($onTimeScore * $weights[0]) + ($qualityScore * $weights[1])
               + ($ncrScore * $weights[2]) + ($priceScore * $weights[3])
               + ($leadTimeScore * $weights[4]);

        return round($score, 2);
    }

    private function setting(string $key): float
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (float) $value < 0) {
            throw new \App\Common\Exceptions\BusinessRuleException("Required supplier policy {$key} is missing or invalid.");
        }
        return (float) $value;
    }

    private function settingInt(string $key, int $minimum, int $maximum): int
    {
        return $this->settings->requiredInt($key, $minimum, $maximum);
    }
}
