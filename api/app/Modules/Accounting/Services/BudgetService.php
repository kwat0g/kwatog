<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    /**
     * Create a budget with line items in one transaction.
     */
    public function create(array $data, array $lineItems): Budget
    {
        return DB::transaction(function () use ($data, $lineItems): Budget {
            $data['total_allocated'] = array_sum(array_map(
                fn ($li) => ($li['jan'] ?? 0) + ($li['feb'] ?? 0) + ($li['mar'] ?? 0)
                    + ($li['apr'] ?? 0) + ($li['may'] ?? 0) + ($li['jun'] ?? 0)
                    + ($li['jul'] ?? 0) + ($li['aug'] ?? 0) + ($li['sep'] ?? 0)
                    + ($li['oct'] ?? 0) + ($li['nov'] ?? 0) + ($li['dec'] ?? 0),
                $lineItems,
            ));

            $budget = Budget::create($data);

            foreach ($lineItems as $li) {
                $li['budget_id'] = $budget->id;
                BudgetLineItem::create($li);
            }

            $budget->load('lineItems');
            return $budget;
        });
    }

    /**
     * Submit budget for approval.
     */
    public function submit(Budget $budget, int $userId): Budget
    {
        $budget->update([
            'status'       => 'submitted',
            'submitted_by' => $userId,
            'submitted_at' => now(),
        ]);
        return $budget->fresh();
    }

    /**
     * Approve budget (makes it active).
     */
    public function approve(Budget $budget, int $userId): Budget
    {
        $budget->update([
            'status'      => 'active',
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);
        return $budget->fresh();
    }

    /**
     * Close a budget.
     */
    public function close(Budget $budget): Budget
    {
        $budget->update(['status' => 'closed']);
        return $budget->fresh();
    }

    /**
     * Check budget consumption level and return warning severity.
     * Returns 'ok' | 'warning' | 'critical' | 'exhausted' | 'overdrawn'
     */
    public function checkConsumption(Budget $budget): string
    {
        $pct = $budget->utilization_percent;
        if ($pct >= 120) return 'overdrawn';
        if ($pct >= 100) return 'exhausted';
        if ($pct >= 95)  return 'critical';
        if ($pct >= 80)  return 'warning';
        return 'ok';
    }

    /**
     * Get budget overview for a fiscal year, grouped by department.
     */
    public function overview(int $fiscalYearId): array
    {
        $budgets = Budget::with('department')
            ->byFiscalYear($fiscalYearId)
            ->get();

        $totalAllocated = $budgets->sum('total_allocated');
        $totalSpent     = $budgets->sum('total_spent');
        $totalCommitted = $budgets->sum('total_committed');

        $byDepartment = $budgets->groupBy(fn ($b) => $b->department_id ?? 0)
            ->map(fn ($deptBudgets, $deptId) => [
                'department' => $deptId === 0 ? 'Company-wide' : ($deptBudgets->first()->department?->name ?? 'Unknown'),
                'allocated'  => (float) $deptBudgets->sum('total_allocated'),
                'spent'      => (float) $deptBudgets->sum('total_spent'),
                'committed'  => (float) $deptBudgets->sum('total_committed'),
                'available'  => (float) $deptBudgets->sum(fn ($b) => $b->available),
                'pct'        => $totalAllocated > 0
                    ? round($deptBudgets->sum(fn ($b) => $b->total_spent + $b->total_committed) / max($deptBudgets->sum('total_allocated'), 1) * 100, 1)
                    : 0,
            ])->values();

        return [
            'total_allocated' => (float) $totalAllocated,
            'total_spent'     => (float) $totalSpent,
            'total_committed' => (float) $totalCommitted,
            'total_available' => (float) ($totalAllocated - $totalSpent - $totalCommitted),
            'utilization_pct' => $totalAllocated > 0
                ? round(($totalSpent + $totalCommitted) / $totalAllocated * 100, 1)
                : 0,
            'by_department'   => $byDepartment,
        ];
    }

    /**
     * Get budget-vs-actual comparison (P&L style) for a fiscal year.
     *
     * NOTE: BudgetLineItem.actual_total is populated by the SyncBudgetActuals
     * job (dispatched via `php artisan budget:sync-actuals` or the monthly
     * scheduled task on the 1st at 03:00).  Actuals reflect GL net movement
     * (SUM debit - SUM credit) from posted JournalEntryLine records for the
     * same GL account within the fiscal year date range.
     *
     * If actual_total appears stale or zero for all line items, run the sync
     * first: POST /api/v1/budgets/sync-actuals or `php artisan budget:sync-actuals`.
     */
    public function budgetVsActual(int $fiscalYearId): array
    {
        $budgets = Budget::with('lineItems.account')
            ->byFiscalYear($fiscalYearId)
            ->active()
            ->get();

        $rows = [];
        foreach ($budgets as $budget) {
            foreach ($budget->lineItems as $li) {
                $rows[] = [
                    'budget_id'    => $budget->hash_id,
                    'account_code' => $li->account?->code,
                    'account_name' => $li->account?->name,
                    'budget_type'  => $budget->budget_type,
                    'department'   => $budget->department?->name ?? 'Company-wide',
                    'budgeted'     => (float) $li->annual_total,
                    'actual'       => (float) $li->actual_total,
                    'variance'     => (float) $li->variance,
                    'variance_pct' => $li->annual_total > 0
                        ? round($li->variance / $li->annual_total * 100, 1)
                        : 0,
                ];
            }
        }

        $totalBudgeted = array_sum(array_column($rows, 'budgeted'));
        $totalActual   = array_sum(array_column($rows, 'actual'));

        return [
            'rows'          => $rows,
            'total_budgeted' => (float) $totalBudgeted,
            'total_actual'   => (float) $totalActual,
            'total_variance' => (float) ($totalBudgeted - $totalActual),
        ];
    }

    public function getCurrentFiscalYear(): ?FiscalYear
    {
        return FiscalYear::current()->active()->first()
            ?? FiscalYear::active()->orderByDesc('year')->first();
    }
}
