<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Support\WidgetScope;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Quality\Services\DefectParetoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rich payloads for the domains that already had aggregate queries.
 * Read-only. Every method returns one of the four documented shapes.
 */
final class CoreWidgetAnalytics
{
    public function __construct(
        private readonly WidgetScope $scope,
        private readonly DefectParetoService $pareto,
    ) {}

    /** @return list<string> */
    public function handles(): array
    {
        // These are the EXISTING seeded widget keys (DashboardWidgetSeeder).
        // Enrichment upgrades widgets users already have on their layouts —
        // inventing new keys would create widgets no layout references.
        return [
            'qc.pareto',
            'production.wo_breakdown',
            'production.kpi',
            'machine.utilization',
            'oee.gauges',
            'finance.ar_aging',
            'hr.headcount',
            'purchasing.open_pos',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        return match ($key) {
            'qc.pareto' => $this->defectPareto(),
            // wo_breakdown is a status mix (a breakdown); production.kpi is the
            // daily output figure, which is what carries a 14-day trend.
            'production.wo_breakdown' => $this->woStatusMix(),
            'production.kpi' => $this->outputTrend(),
            'machine.utilization', 'oee.gauges' => $this->oeeGauge(),
            'finance.ar_aging' => $this->arAging(),
            'hr.headcount' => $this->headcount($user),
            'purchasing.open_pos' => $this->poStatusMix(),
            default => [],
        };
    }

    /**
     * Top defect parameters. Delegates to DefectParetoService — the Quality
     * module already owns this aggregation (is_pass=false over
     * inspection_measurements joined to inspections, with the portable
     * BOOL_OR critical aggregate). Re-deriving it here would fork the
     * definition of "a defect" between two places.
     *
     * @return array<string, mixed>
     */
    private function defectPareto(): array
    {
        $result = $this->pareto->run(['limit' => 6]);

        return [
            'total' => (int) $result['total_defects'],
            'segments' => array_map(fn (array $row) => [
                'label' => $row['parameter_name'],
                'value' => $row['defect_count'],
                // A critical parameter is the one to fix first; the tone is
                // what makes that readable at a glance on the tile.
                'tone' => $row['is_critical'] ? 'danger' : 'warning',
            ], $result['rows']),
        ];
    }

    /**
     * Good output per day, trailing 14 days, zero-filled.
     *
     * @return array<string, mixed>
     */
    private function outputTrend(): array
    {
        $rows = DB::table('work_order_outputs')
            ->selectRaw('DATE(recorded_at) as day, COALESCE(SUM(good_count), 0) as value')
            ->where('recorded_at', '>=', Carbon::now()->subDays(14)->startOfDay())
            ->groupBy('day')
            ->pluck('value', 'day');

        $points = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = Carbon::now()->subDays($i)->toDateString();
            $points[] = ['label' => $day, 'value' => (int) ($rows[$day] ?? 0)];
        }

        $first = $points[0]['value'];
        $last = end($points)['value'];

        return [
            'points' => $points,
            'delta' => $first > 0 ? round((($last - $first) / $first) * 100, 1) : null,
            'kind' => 'count',
        ];
    }

    /**
     * Trailing-7-day availability as an OEE-style gauge.
     *
     * @return array<string, mixed>
     */
    private function oeeGauge(): array
    {
        $downtime = (float) DB::table('machine_downtimes')
            ->where('start_time', '>=', Carbon::now()->subDays(7))
            ->sum('duration_minutes');

        $machines = max(1, (int) DB::table('machines')->count());
        $capacity = $machines * 7 * 24 * 60;
        $availability = $capacity > 0 ? max(0.0, min(100.0, (1 - ($downtime / $capacity)) * 100)) : 0.0;

        return [
            'value' => round($availability, 1),
            'target' => 85.0,
            'min' => 0.0,
            'max' => 100.0,
            'kind' => 'percent',
        ];
    }

    /**
     * Work orders by status — the real breakdown that `production.wo_breakdown`
     * has always been describing, except `DashboardWidgetDataService::breakdown()`
     * flattened it into a helper string and threw the segments away.
     *
     * @return array<string, mixed>
     */
    private function woStatusMix(): array
    {
        $tone = [
            WorkOrderStatus::Planned->value => 'neutral',
            WorkOrderStatus::Confirmed->value => 'info',
            WorkOrderStatus::InProgress->value => 'success',
            WorkOrderStatus::Paused->value => 'warning',
            WorkOrderStatus::Completed->value => 'success',
            WorkOrderStatus::Closed->value => 'neutral',
            WorkOrderStatus::Cancelled->value => 'danger',
        ];

        $rows = DB::table('work_orders')
            ->selectRaw('status as label, COUNT(*) as value')
            ->groupBy('status')
            ->orderByDesc('value')
            ->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($r) => [
                'label' => (string) $r->label,
                'value' => (int) $r->value,
                'tone' => $tone[(string) $r->label] ?? 'neutral',
            ])->values()->all(),
        ];
    }

    /**
     * Open receivables bucketed by age. Ages on `balance`, not
     * `total_amount` — a partially paid invoice is only outstanding for what
     * is left, and InvoiceStatus::Partial rows would otherwise be counted at
     * full face value.
     *
     * @return array<string, mixed>
     */
    private function arAging(): array
    {
        $buckets = [
            ['label' => 'Current', 'from' => -100000, 'to' => 0, 'tone' => 'success'],
            ['label' => '1-30', 'from' => 1, 'to' => 30, 'tone' => 'neutral'],
            ['label' => '31-60', 'from' => 31, 'to' => 60, 'tone' => 'warning'],
            ['label' => '60+', 'from' => 61, 'to' => 100000, 'tone' => 'danger'],
        ];

        $segments = [];
        $total = 0.0;
        foreach ($buckets as $bucket) {
            $amount = (float) DB::table('invoices')
                ->whereIn('status', [InvoiceStatus::Finalized->value, InvoiceStatus::Partial->value])
                ->where('balance', '>', 0)
                ->whereRaw('CURRENT_DATE - due_date BETWEEN ? AND ?', [$bucket['from'], $bucket['to']])
                ->sum('balance');

            $total += $amount;
            $segments[] = [
                'label' => $bucket['label'],
                'value' => round($amount, 2),
                'tone' => $bucket['tone'],
            ];
        }

        return ['total' => round($total, 2), 'segments' => $segments];
    }

    /**
     * Active headcount per department. Department-scoped viewers see only
     * their own row — the same permission gate the HR widgets already use.
     *
     * @return array<string, mixed>
     */
    private function headcount(User $user): array
    {
        $query = DB::table('employees')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->selectRaw('departments.name as label, COUNT(*) as value')
            ->where('employees.status', 'active')
            ->whereNull('employees.deleted_at')
            ->groupBy('departments.name')
            ->orderByDesc('value');

        if (! $this->scope->isCompanyWide($user, 'hr.employees.view')) {
            $departmentId = $this->scope->departmentId($user);
            if ($departmentId === null) {
                return ['total' => 0, 'segments' => []];
            }
            $query->where('employees.department_id', $departmentId);
        }

        $rows = $query->limit(8)->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($r) => [
                'label' => (string) $r->label,
                'value' => (int) $r->value,
                'tone' => 'neutral',
            ])->values()->all(),
        ];
    }

    /**
     * Open purchase orders by status.
     *
     * @return array<string, mixed>
     */
    private function poStatusMix(): array
    {
        $tone = [
            PurchaseOrderStatus::Draft->value => 'neutral',
            PurchaseOrderStatus::PendingApproval->value => 'warning',
            PurchaseOrderStatus::Approved->value => 'success',
            PurchaseOrderStatus::Sent->value => 'success',
            PurchaseOrderStatus::PartiallyReceived->value => 'warning',
            PurchaseOrderStatus::Received->value => 'success',
            PurchaseOrderStatus::Closed->value => 'neutral',
            PurchaseOrderStatus::Cancelled->value => 'danger',
        ];

        $rows = DB::table('purchase_orders')
            ->selectRaw('status as label, COUNT(*) as value')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->orderByDesc('value')
            ->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($r) => [
                'label' => (string) $r->label,
                'value' => (int) $r->value,
                'tone' => $tone[(string) $r->label] ?? 'neutral',
            ])->values()->all(),
        ];
    }
}
