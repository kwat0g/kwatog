<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Models\SupplierPerformanceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    public const SCORE_ALERT_THRESHOLD = 80.0;

    public function compute(Vendor $vendor, int $year, int $month): SupplierPerformanceSnapshot
    {
        return DB::transaction(function () use ($vendor, $year, $month) {
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

            return SupplierPerformanceSnapshot::updateOrCreate(
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
        });
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
        if ($score >= 90) return 'A';
        if ($score >= 75) return 'B';
        if ($score >= 60) return 'C';
        return 'D';
    }

    /**
     * @return Collection<int, SupplierPerformanceSnapshot>
     */
    public function trendForVendor(Vendor $vendor, int $months = 6): Collection
    {
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

    /** Recompute snapshots for every vendor for the given month. */
    public function recomputeAll(int $year, int $month): int
    {
        $count = 0;
        Vendor::query()->orderBy('id')->chunk(100, function ($vendors) use (&$count, $year, $month) {
            foreach ($vendors as $vendor) {
                $this->compute($vendor, $year, $month);
                $count++;
            }
        });
        return $count;
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
            $expected = Carbon::parse((string) $r->po_date)->diffInDays(Carbon::parse((string) $r->expected_delivery_date));
            $actual   = Carbon::parse((string) $r->po_date)->diffInDays(Carbon::parse((string) $r->received_date));
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
        $ncrScore      = $ncrRate === null ? 50 : max(0, 100 - $ncrRate * 2);  // 0% NCR → 100
        $priceScore    = $price === null ? 50 : max(0, 100 - $price * 2);       // 0% var → 100
        $leadTimeScore = $leadTime === null ? 50 : max(0, 100 - abs($leadTime) * 5);

        $score = ($onTimeScore * 0.25)
               + ($qualityScore * 0.35)
               + ($ncrScore     * 0.10)
               + ($priceScore   * 0.15)
               + ($leadTimeScore * 0.15);

        return round($score, 2);
    }
}
