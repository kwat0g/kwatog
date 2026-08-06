<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Dashboard\Enums\KpiDirection;
use App\Modules\Dashboard\Enums\KpiStatus;
use App\Modules\Dashboard\Enums\KpiTrend;
use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Models\KpiSnapshot;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KpiSnapshotService
{
    private const MODULE_PERMISSIONS = [
        'production' => 'production.dashboard.view',
        'quality' => 'dashboard.quality.view',
        'supply_chain' => 'dashboard.ppc.view',
        'purchasing' => 'dashboard.purchasing.view',
        'attendance' => 'dashboard.hr.view',
        'accounting' => 'accounting.dashboard.view',
        'inventory' => 'dashboard.warehouse.view',
    ];

    public function computeAll(int $year, int $month): void
    {
        $definitions = KpiDefinition::active()->orderBy('display_order')->get();
        foreach ($definitions as $def) {
            try {
                $this->computeKpi($def, $year, $month);
            } catch (\Throwable $e) {
                Log::warning("KPI compute failed: {$def->code}", ['error' => $e->getMessage()]);
            }
        }
    }

    public function computeKpi(KpiDefinition $def, int $year, int $month): ?KpiSnapshot
    {
        $method = $def->calculation_method;
        if (! method_exists($this, $method)) {
            throw new \UnexpectedValueException("Unsupported KPI calculation method [{$method}].");
        }
        $actual = $this->{$method}($year, $month);

        // Do not persist a synthetic zero when a KPI has no observations for
        // the period. The scorecard will expose the KPI definition without a
        // snapshot until there is authoritative source data.
        if ($actual === null) {
            return null;
        }

        if ($def->target_value === null) {
            throw new \App\Common\Exceptions\BusinessRuleException(
                "KPI [{$def->code}] has no configured target value. Configure it before computing a snapshot."
            );
        }

        $previous = KpiSnapshot::where('definition_id', $def->id)
            ->where(function ($q) use ($year, $month) {
                $prev = Carbon::create($year, $month, 1)->subMonth();
                $q->where('period_year', $prev->year)->where('period_month', $prev->month);
            })
            ->value('actual_value');

        $trend = $this->determineTrend($actual, $previous);
        $status = $this->determineStatus($actual, $def);

        return KpiSnapshot::updateOrCreate(
            ['definition_id' => $def->id, 'period_year' => $year, 'period_month' => $month],
            [
                'actual_value' => round($actual, 4),
                'target_value' => $def->target_value,
                'previous_value' => $previous,
                'trend' => $trend,
                'status' => $status,
                'computed_at' => now(),
            ]
        );
    }

    public function getScorecard(int $year, int $month, ?Authenticatable $user = null): array
    {
        $definitions = KpiDefinition::active()->orderBy('display_order')->get();

        if ($user) {
            $definitions = $definitions->filter(fn (KpiDefinition $def) => $this->userCanSeeModule($user, $def->module));
        }

        return $definitions->values()->map(function (KpiDefinition $def) use ($year, $month) {
            $snapshot = KpiSnapshot::where('definition_id', $def->id)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->first();

            return [
                'definition' => [
                    'id' => $def->hash_id,
                    'code' => $def->code,
                    'name' => $def->name,
                    'module' => $def->module,
                    'unit' => $def->unit->value,
                    'direction' => $def->direction->value,
                    'target_value' => $def->target_value,
                    'warning_threshold' => $def->warning_threshold,
                ],
                'snapshot' => $snapshot ? [
                    'actual_value' => $snapshot->actual_value,
                    'target_value' => $snapshot->target_value,
                    'previous_value' => $snapshot->previous_value,
                    'trend' => $snapshot->trend?->value,
                    'status' => $snapshot->status?->value,
                    'status_label' => Str::headline((string) $snapshot->status?->value),
                    'computed_at' => $snapshot->computed_at?->toIso8601String(),
                ] : null,
            ];
        })->values()->all();
    }

    public function getTrend(string $kpiCode, int $months = 12, ?Authenticatable $user = null): array
    {
        $def = KpiDefinition::where('code', $kpiCode)->firstOrFail();

        if ($user && ! $this->userCanSeeModule($user, $def->module)) {
            abort(403, 'You do not have permission to view this KPI.');
        }

        return KpiSnapshot::where('definition_id', $def->id)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->limit($months)
            ->get()
            ->sortBy(fn ($s) => sprintf('%04d-%02d', $s->period_year, $s->period_month))
            ->values()
            ->map(fn ($s) => [
                'period' => sprintf('%04d-%02d', $s->period_year, $s->period_month),
                'value' => $s->actual_value,
                'target' => $s->target_value,
                'status' => $s->status?->value,
                'status_label' => Str::headline((string) $s->status?->value),
            ])
            ->all();
    }

    private function userCanSeeModule(Authenticatable $user, string $module): bool
    {
        $permission = self::MODULE_PERMISSIONS[$module] ?? null;
        if ($permission === null) {
            return true;
        }

        return $user->can($permission);
    }

    private function determineTrend(float $actual, ?string $previous): KpiTrend
    {
        if ($previous === null) {
            return KpiTrend::Flat;
        }
        $prev = (float) $previous;
        $delta = abs($actual - $prev);
        $threshold = max(abs($prev) * 0.01, 0.01);
        if ($delta < $threshold) {
            return KpiTrend::Flat;
        }

        return $actual > $prev ? KpiTrend::Up : KpiTrend::Down;
    }

    private function determineStatus(float $actual, KpiDefinition $def): KpiStatus
    {
        if ($def->target_value === null) {
            return KpiStatus::OnTarget;
        }
        $target = (float) $def->target_value;
        $warning = $def->warning_threshold !== null ? (float) $def->warning_threshold : null;

        if ($def->direction === KpiDirection::HigherIsBetter) {
            if ($actual >= $target) {
                return KpiStatus::OnTarget;
            }
            if ($warning !== null && $actual >= $warning) {
                return KpiStatus::Warning;
            }

            return KpiStatus::OffTarget;
        } else {
            if ($actual <= $target) {
                return KpiStatus::OnTarget;
            }
            if ($warning !== null && $actual <= $warning) {
                return KpiStatus::Warning;
            }

            return KpiStatus::OffTarget;
        }
    }

    // ─── Calculator Methods ─────────────────────────────────────────

    private function computeOee(int $year, int $month): ?float
    {
        // OEE = Availability x Performance x Quality from work_orders completed in the period
        $from = Carbon::create($year, $month, 1)->startOfDay()->toDateTimeString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateTimeString();

        $wos = DB::table('work_orders')
            ->whereIn('status', ['completed', 'closed'])
            ->whereBetween('actual_end', [$from, $to])
            ->whereNotNull('actual_start')
            ->get(['machine_id', 'actual_start', 'actual_end', 'quantity_target', 'quantity_good', 'quantity_rejected']);

        if ($wos->isEmpty()) {
            return null;
        }

        $totalPlanned = 0;
        $totalActual = 0;
        $totalGood = 0;
        $totalProduced = 0;
        $machineIds = [];
        foreach ($wos as $wo) {
            $planned = (int) $wo->quantity_target;
            $good = (int) $wo->quantity_good;
            $rejected = (int) $wo->quantity_rejected;
            $produced = $good + $rejected;
            $totalPlanned += $planned;
            $totalGood += $good;
            $totalProduced += $produced;
            $totalActual += max(1, (int) Carbon::parse($wo->actual_start)->diffInMinutes(Carbon::parse($wo->actual_end)));
            if ($wo->machine_id !== null) $machineIds[(int) $wo->machine_id] = true;
        }

        if ($totalPlanned == 0 || $totalActual == 0 || $totalProduced == 0) {
            return null;
        }

        $performance = min(1.0, $totalProduced / $totalPlanned);
        $quality = $totalGood / $totalProduced;
        $scheduledMinutes = 0;
        if ($machineIds !== [] && DB::getSchemaBuilder()->hasTable('machines')) {
            $workingDays = 0;
            $cursor = Carbon::create($year, $month, 1)->startOfDay();
            $end = $cursor->copy()->endOfMonth();
            while ($cursor->lte($end)) {
                if (! $cursor->isWeekend()) $workingDays++;
                $cursor->addDay();
            }
            $hours = (float) DB::table('machines')->whereIn('id', array_keys($machineIds))->sum('available_hours_per_day');
            $scheduledMinutes = (int) round($hours * $workingDays * 60);
        }
        $availability = $scheduledMinutes > 0
            ? min(1.0, $totalActual / $scheduledMinutes)
            : 0.0;

        return round($availability * $performance * $quality * 100, 2);
    }

    private function computeDppm(int $year, int $month): ?float
    {
        $from = Carbon::create($year, $month, 1)->startOfDay()->toDateTimeString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateTimeString();

        $totalInspected = (int) DB::table('inspections')
            ->whereBetween('completed_at', [$from, $to])
            ->sum('sample_size');
        $totalDefects = (int) DB::table('inspections')
            ->whereBetween('completed_at', [$from, $to])
            ->sum('defects_found');

        if ($totalInspected == 0) {
            return null;
        }

        return round(($totalDefects / $totalInspected) * 1_000_000, 2);
    }

    private function computeFirstPassYield(int $year, int $month): ?float
    {
        $from = Carbon::create($year, $month, 1)->startOfDay()->toDateTimeString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateTimeString();

        $total = (int) DB::table('inspections')
            ->whereBetween('completed_at', [$from, $to])
            ->count();
        $passed = (int) DB::table('inspections')
            ->where('status', 'passed')
            ->whereBetween('completed_at', [$from, $to])
            ->count();

        if ($total == 0) {
            return null;
        }

        return round(($passed / $total) * 100, 2);
    }

    private function computeOnTimeDelivery(int $year, int $month): ?float
    {
        $from = Carbon::create($year, $month, 1)->startOfDay()->toDateTimeString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateTimeString();

        $total = (int) DB::table('deliveries')
            ->where('status', 'confirmed')
            ->whereBetween('confirmed_at', [$from, $to])
            ->count();
        $onTime = (int) DB::table('deliveries')
            ->where('status', 'confirmed')
            ->whereBetween('confirmed_at', [$from, $to])
            ->whereColumn('delivered_at', '<=', 'scheduled_date')
            ->count();

        if ($total == 0) {
            return null;
        }

        return round(($onTime / $total) * 100, 2);
    }

    private function computeSupplierQuality(int $year, int $month): ?float
    {
        // Average supplier performance score for the month
        if (! DB::getSchemaBuilder()->hasTable('supplier_performance_scores')) {
            return null;
        }
        $avg = DB::table('supplier_performance_scores')
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->avg('overall_score');

        return $avg === null ? null : round((float) $avg, 2);
    }

    private function computeCopqPctRevenue(int $year, int $month): ?float
    {
        // COPQ total / revenue (from invoices) * 100
        $copqSnap = DB::table('copq_snapshots')
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();
        $copqTotal = (float) ($copqSnap->total_cost ?? 0);

        $from = Carbon::create($year, $month, 1)->startOfDay()->toDateTimeString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateTimeString();
        $revenue = (float) DB::table('invoices')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->sum('total_amount');

        if ($revenue == 0) {
            return null;
        }

        return round(($copqTotal / $revenue) * 100, 4);
    }

    private function computeAttendanceRate(int $year, int $month): ?float
    {
        $from = Carbon::create($year, $month, 1)->startOfDay()->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $total = (int) DB::table('daily_time_records')
            ->whereBetween('date', [$from, $to])
            ->count();
        $present = (int) DB::table('daily_time_records')
            ->whereBetween('date', [$from, $to])
            ->whereNotNull('time_in')
            ->count();

        if ($total == 0) {
            return null;
        }

        return round(($present / $total) * 100, 2);
    }

    private function computeArAging60d(int $year, int $month): ?float
    {
        // Percentage of AR balance that is over 60 days old
        $lookbackDays = app(\App\Common\Services\SettingsService::class)->requiredInt('dashboard.kpi_snapshot_lookback_days', 1, 3650);
        $cutoff = Carbon::create($year, $month, 1)->endOfMonth()->subDays($lookbackDays)->toDateString();

        $totalAr = (float) DB::table('invoices')
            ->where('status', '!=', 'paid')
            ->where('status', '!=', 'cancelled')
            ->sum('balance_due');
        $overdue = (float) DB::table('invoices')
            ->where('status', '!=', 'paid')
            ->where('status', '!=', 'cancelled')
            ->where('due_date', '<', $cutoff)
            ->sum('balance_due');

        if ($totalAr == 0) {
            return null;
        }

        return round(($overdue / $totalAr) * 100, 2);
    }

    private function computeBudgetUtilization(int $year, int $month): ?float
    {
        if (! DB::getSchemaBuilder()->hasTable('budget_line_items')) {
            return null;
        }
        $totalBudget = (float) DB::table('budget_line_items')
            ->join('budgets', 'budget_line_items.budget_id', '=', 'budgets.id')
            ->join('fiscal_years', 'budgets.fiscal_year_id', '=', 'fiscal_years.id')
            ->where('fiscal_years.year', $year)
            ->sum('budget_line_items.budgeted_amount');
        $totalActual = (float) DB::table('budget_line_items')
            ->join('budgets', 'budget_line_items.budget_id', '=', 'budgets.id')
            ->join('fiscal_years', 'budgets.fiscal_year_id', '=', 'fiscal_years.id')
            ->where('fiscal_years.year', $year)
            ->sum('budget_line_items.actual_total');

        if ($totalBudget == 0) {
            return null;
        }

        return round(($totalActual / $totalBudget) * 100, 2);
    }

    private function computeNcrClosureDays(int $year, int $month): ?float
    {
        $from = Carbon::create($year, $month, 1)->startOfDay()->toDateTimeString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateTimeString();

        $avg = DB::table('non_conformance_reports')
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$from, $to])
            ->whereNotNull('created_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (closed_at - created_at)) / 86400) as avg_days')
            ->value('avg_days');

        return $avg === null ? null : round((float) $avg, 2);
    }

    private function computeInventoryTurnover(int $year, int $month): ?float
    {
        // COGS / Average inventory value. Simplified: use issued qty * cost
        $from = Carbon::create($year, $month, 1)->startOfDay()->toDateTimeString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateTimeString();

        if (! DB::getSchemaBuilder()->hasTable('stock_movements')) {
            return null;
        }

        $cogs = (float) DB::table('stock_movements')
            ->where('type', 'issue')
            ->whereBetween('created_at', [$from, $to])
            ->sum(DB::raw('ABS(quantity) * unit_cost'));

        $avgInventory = (float) DB::table('stock_levels')
            ->sum(DB::raw('quantity * unit_cost'));

        if ($avgInventory == 0) {
            return null;
        }

        return round($cogs / $avgInventory * 12, 2); // annualized
    }

    private function computeWoCompletionRate(int $year, int $month): ?float
    {
        $from = Carbon::create($year, $month, 1)->startOfDay()->toDateTimeString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateTimeString();

        $total = (int) DB::table('work_orders')
            ->whereIn('status', ['completed', 'closed', 'started', 'paused'])
            ->whereBetween('scheduled_end', [$from, $to])
            ->count();
        $completed = (int) DB::table('work_orders')
            ->whereIn('status', ['completed', 'closed'])
            ->whereBetween('actual_end', [$from, $to])
            ->count();

        if ($total == 0) {
            return null;
        }

        return round(($completed / $total) * 100, 2);
    }
}
