<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Concerns\DashboardQueries;
use App\Modules\Dashboard\Services\ForecastingDashboardService;
use App\Modules\Quality\Services\CopqService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4.1 extraction — QC Inspector dashboard.
 * Owns: quality, qualityPassRateToday, qualityInspectionQueue,
 *       qualityNcrList, qualityChainCoverage.
 * Shared defectPareto comes from DashboardQueries trait.
 */
class QualityDashboardService
{
    use DashboardQueries;

    private const CACHE_TTL = 30;

    public function __construct(
        private readonly ForecastingDashboardService $forecastingService,
    ) {}

    public function quality(User $user): array
    {
        return Cache::remember("dashboard:quality:{$user->id}", self::CACHE_TTL, function () {
            $pendingInspections = $this->safeCount('inspections', fn ($q) => $q->where('status', 'in_progress'));
            $passRate           = $this->qualityPassRateToday();
            $openNcrs           = $this->safeCount('non_conformance_reports', fn ($q) => $q->whereIn('status', ['open', 'in_progress']));
            $cocsMtd            = $this->safeCount('non_conformance_reports', fn ($q) => $q->where('status', 'closed'));

            return [
                'kpis' => [
                    $this->kpi('Pending Inspections', (string) $pendingInspections, 'count'),
                    $this->kpi('Pass Rate Today',      $passRate,                   'pct'),
                    $this->kpi('Open NCRs',            (string) $openNcrs,          'count'),
                    $this->kpi('CoCs Gen. MTD',        (string) $cocsMtd,           'count'),
                ],
                'panels' => [
                    'inspection_queue'  => $this->qualityInspectionQueue(),
                    'defect_pareto'     => $this->defectPareto(),
                    'ncr_status'        => $this->qualityNcrList(),
                    'qc_chain_coverage' => $this->qualityChainCoverage(),
                    'defect_rate_forecast' => $this->forecastingService->defectRateForecast(),
                    'copq'              => $this->copq()->compute(now()->startOfMonth(), now()->endOfMonth()),
                ],
            ];
        });
    }

    private function copq(): CopqService
    {
        return app(CopqService::class);
    }

    private function qualityPassRateToday(): string
    {
        if (! Schema::hasTable('inspections')) return '0.0';
        $total = (int) DB::table('inspections')->whereDate('created_at', today())->count();
        if ($total === 0) return '0.0';
        $passed = (int) DB::table('inspections')->whereDate('created_at', today())->where('result', 'pass')->count();
        return number_format(($passed * 100.0) / $total, 1, '.', '');
    }

    /**
     * @return array<int, array{id: string, inspection_number: string, stage: string, product: string, batch_no: string|null, qty: string, waiting_since: string}>
     */
    private function qualityInspectionQueue(): array
    {
        if (! Schema::hasTable('inspections') || ! Schema::hasTable('items')) return [];
        return DB::table('inspections as i')
            ->leftJoin('items as it', 'it.id', '=', 'i.product_id')
            ->where('i.status', 'in_progress')
            ->select('i.id', 'i.inspection_number', 'i.stage', 'it.name as product_name', 'i.batch_no', 'i.qty', 'i.created_at')
            ->orderByRaw("CASE i.stage WHEN 'outgoing' THEN 0 WHEN 'in_process' THEN 1 WHEN 'incoming' THEN 2 END")
            ->orderBy('i.created_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id'                => app('hashids')->encode((int) $r->id),
                'inspection_number' => $r->inspection_number,
                'stage'             => $r->stage,
                'product'           => $r->product_name ?? '—',
                'batch_no'          => $r->batch_no,
                'qty'               => (string) ($r->qty ?? '0'),
                'waiting_since'     => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: string, ncr_number: string, severity: string, customer: string, defect_code: string, status: string}>
     */
    private function qualityNcrList(): array
    {
        if (! Schema::hasTable('non_conformance_reports')) return [];
        return DB::table('non_conformance_reports as ncr')
            ->leftJoin('customers as c', 'c.id', '=', 'ncr.customer_id')
            ->whereIn('ncr.status', ['open', 'in_progress'])
            ->select('ncr.id', 'ncr.ncr_number', 'ncr.severity', 'c.name as customer_name', 'ncr.defect_code', 'ncr.status')
            ->orderByRaw("CASE ncr.severity WHEN 'critical' THEN 0 WHEN 'major' THEN 1 WHEN 'minor' THEN 2 END")
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id'          => app('hashids')->encode((int) $r->id),
                'ncr_number'  => $r->ncr_number,
                'severity'    => $r->severity,
                'customer'    => $r->customer_name ?? 'Internal',
                'defect_code' => $r->defect_code ?? '—',
                'status'      => $r->status,
            ])
            ->all();
    }

    /**
     * @return array{incoming: array{inspected: int, total: int, pct: int}, in_process: array{inspected: int, total: int, pct: int}, outgoing: array{inspected: int, total: int, pct: int}}
     */
    private function qualityChainCoverage(): array
    {
        $result = [
            'incoming'   => ['inspected' => 0, 'total' => 0, 'pct' => 0],
            'in_process' => ['inspected' => 0, 'total' => 0, 'pct' => 0],
            'outgoing'   => ['inspected' => 0, 'total' => 0, 'pct' => 0],
        ];
        if (Schema::hasTable('goods_receipt_notes')) {
            $result['incoming']['total'] = (int) DB::table('goods_receipt_notes')->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count();
        }
        if (Schema::hasTable('inspections')) {
            $result['incoming']['inspected']   = (int) DB::table('inspections')->where('stage', 'incoming')->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count();
            $result['in_process']['inspected'] = (int) DB::table('inspections')->where('stage', 'in_process')->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count();
            $result['outgoing']['inspected']   = (int) DB::table('inspections')->where('stage', 'outgoing')->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count();
        }
        if (Schema::hasTable('work_orders')) {
            $result['in_process']['total'] = (int) DB::table('work_orders')->whereIn('status', ['confirmed', 'in_progress'])->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count();
            $result['outgoing']['total']   = (int) DB::table('work_orders')->where('status', 'completed')->whereBetween('updated_at', [now()->startOfWeek(), now()->endOfWeek()])->count();
        }
        foreach ($result as &$r) {
            $r['pct'] = (int) round(($r['inspected'] * 100) / max(1, $r['total']));
        }
        return $result;
    }
}
