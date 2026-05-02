<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderDefect;
use App\Modules\Production\Models\WorkOrderOutput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Sprint 6 — Task 58. Plant-manager dashboard payload.
 *
 * Composes existing services (no new business logic). Cached for 30s in
 * Redis (or array driver in tests) and invalidated implicitly by TTL.
 */
class ProductionDashboardService
{
    private const CACHE_KEY = 'dashboard:production';
    private const CACHE_TTL_SECONDS = 30;

    public function __construct(private readonly OeeService $oee) {}

    public function payload(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $today = Carbon::today();
            $start = $today->copy();
            $end   = $today->copy()->endOfDay();

            return [
                'kpis'                   => $this->kpis($start, $end),
                'chain_stage_breakdown'  => $this->chainStageBreakdown(),
                'machine_utilization'    => $this->oee->calculateForAllMachines($start, $end),
                'alerts'                 => $this->alerts(),
                'defect_pareto'          => $this->defectPareto($start->copy()->subDays(7), $end), // 7-day window
                'generated_at'           => Carbon::now()->toIso8601String(),
            ];
        });
    }

    private function kpis(Carbon $from, Carbon $to): array
    {
        $todayOutputs = WorkOrderOutput::whereBetween('recorded_at', [$from, $to])->get();
        $todayGood   = (int) $todayOutputs->sum('good_count');
        $todayReject = (int) $todayOutputs->sum('reject_count');

        $machineStatusCounts = Machine::query()
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $totalMachines = array_sum($machineStatusCounts);
        $running   = (int) ($machineStatusCounts[MachineStatus::Running->value]   ?? 0);
        $idle      = (int) ($machineStatusCounts[MachineStatus::Idle->value]      ?? 0);
        $breakdown = (int) ($machineStatusCounts[MachineStatus::Breakdown->value] ?? 0);

        $activeWos = WorkOrder::whereIn('status', [
            WorkOrderStatus::InProgress->value,
            WorkOrderStatus::Paused->value,
        ])->count();

        // Average OEE today across active machines.
        $oeeRows = $this->oee->calculateForAllMachines($from, $to);
        $avgOee = $oeeRows->isEmpty() ? 0 : round((float) $oeeRows->avg('oee'), 4);

        return [
            'today_output_total' => $todayGood + $todayReject,
            'today_output_good'  => $todayGood,
            'today_output_reject'=> $todayReject,
            'active_work_orders' => $activeWos,
            'machines_total'     => $totalMachines,
            'machines_running'   => $running,
            'machines_idle'      => $idle,
            'machines_breakdown' => $breakdown,
            'avg_oee_today'      => $avgOee,
        ];
    }

    /**
     * Stage breakdown payload for the StageBreakdown component.
     * Aggregates SOs by their derived chain stage.
     */
    private function chainStageBreakdown(): array
    {
        // SOs that have not been delivered/invoiced/cancelled.
        $sos = SalesOrder::query()
            ->whereNotIn('status', [
                SalesOrderStatus::Delivered->value,
                SalesOrderStatus::Invoiced->value,
                SalesOrderStatus::Cancelled->value,
            ])
            ->get();

        $total = max(1, $sos->count());

        $stages = [
            'Order Entered'    => 0,
            'MRP Planned'      => 0,
            'In Production'    => 0,
            'QC Pending'       => 0,
            'Ready to Ship'    => 0,
            'Delivered Unpaid' => 0,
            'At Risk'          => 0,
        ];

        foreach ($sos as $so) {
            $key = match (true) {
                $so->status === SalesOrderStatus::Draft         => 'Order Entered',
                $so->status === SalesOrderStatus::Confirmed     => $so->mrp_plan_id ? 'MRP Planned' : 'Order Entered',
                $so->status === SalesOrderStatus::InProduction  => 'In Production',
                $so->status === SalesOrderStatus::PartiallyDelivered => 'Ready to Ship',
                default => 'In Production',
            };
            $stages[$key]++;
        }

        $colors = [
            'Order Entered'    => 'success',
            'MRP Planned'      => 'success',
            'In Production'    => 'info',
            'QC Pending'       => 'info',
            'Ready to Ship'    => 'success',
            'Delivered Unpaid' => 'warning',
            'At Risk'          => 'danger',
        ];

        return collect($stages)->map(fn ($count, $label) => [
            'label'   => $label,
            'count'   => $count,
            'percent' => round(($count / $total) * 100, 1),
            'color'   => $colors[$label] ?? 'neutral',
        ])->values()->all();
    }

    private function alerts(): array
    {
        $alerts = [];

        // Machine breakdowns (status=breakdown right now).
        Machine::where('status', MachineStatus::Breakdown->value)
            ->get()
            ->each(function ($m) use (&$alerts) {
                $alerts[] = [
                    'type'     => 'breakdown',
                    'severity' => 'danger',
                    'message'  => "{$m->machine_code} is in breakdown.",
                    'link'     => "/mrp/machines/{$m->hash_id}",
                ];
            });

        // Molds nearing or past their shot threshold.
        Mold::query()
            ->whereRaw('current_shot_count >= max_shots_before_maintenance * 0.80')
            ->get()
            ->each(function ($mold) use (&$alerts) {
                $pct = $mold->shot_percentage;
                $alerts[] = [
                    'type'     => 'mold_limit',
                    'severity' => $pct >= 100 ? 'danger' : 'warning',
                    'message'  => sprintf(
                        '%s at %.1f%% of maintenance threshold (%d / %d shots).',
                        $mold->mold_code,
                        $pct,
                        (int) $mold->current_shot_count,
                        (int) $mold->max_shots_before_maintenance,
                    ),
                    'link'     => "/mrp/molds/{$mold->hash_id}",
                ];
            });

        // Paused WOs (likely material shortage / breakdown awaiting resolution).
        WorkOrder::where('status', WorkOrderStatus::Paused->value)
            ->limit(10)
            ->get()
            ->each(function ($wo) use (&$alerts) {
                $alerts[] = [
                    'type'     => 'wo_paused',
                    'severity' => 'warning',
                    'message'  => "{$wo->wo_number} is paused" . ($wo->pause_reason ? " — {$wo->pause_reason}" : ''),
                    'link'     => "/production/work-orders/{$wo->hash_id}",
                ];
            });

        return $alerts;
    }

    private function defectPareto(Carbon $from, Carbon $to): array
    {
        $rows = WorkOrderDefect::query()
            ->join('work_order_outputs as wo_out', 'wo_out.id', '=', 'work_order_defects.output_id')
            ->join('defect_types as dt', 'dt.id', '=', 'work_order_defects.defect_type_id')
            ->whereBetween('wo_out.recorded_at', [$from, $to])
            ->selectRaw('dt.code as defect_code, dt.name as defect_name, SUM(work_order_defects.count) as total')
            ->groupBy('dt.code', 'dt.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $sum = (int) $rows->sum('total');
        if ($sum === 0) return [];

        return $rows->map(fn ($r) => [
            'defect_code' => $r->defect_code,
            'defect_name' => $r->defect_name,
            'count'       => (int) $r->total,
            'percent'     => round(((int) $r->total / $sum) * 100, 1),
        ])->all();
    }
}
