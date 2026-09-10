<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Support\Money;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives budget consumption from the accounting and purchasing ledgers.
 *
 * Budget headers remain a cache for compatibility with old/header-only
 * fixtures. A budget with an approved/active line set is authoritative from
 * the source ledgers: posted GL movement supplies actuals and open approved
 * purchase orders supply commitments. Since journal lines do not carry a
 * department, account actuals and commitments are allocated to the matching
 * budget lines in proportion to their annual allocations. The allocation is
 * deterministic and prevents the same account-level movement being counted
 * once per department budget.
 */
final class BudgetConsumptionService
{
    /** @var array<int, string> */
    private const LIVE_STATUSES = ['approved', 'active'];

    /** @var array<int, string> */
    private const COMMITTED_PO_STATUSES = [
        'approved', 'sent', 'partially_received', 'received',
    ];

    /** @var array<int, string> */
    private const MONTHS = [
        'jan', 'feb', 'mar', 'apr', 'may', 'jun',
        'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
    ];

    /**
     * Hydrate exact derived totals onto the supplied models.
     *
     * @param Collection<int, Budget> $budgets
     * @return Collection<int, Budget>
     */
    public function hydrate(Collection $budgets): Collection
    {
        if ($budgets->isEmpty()) {
            return $budgets;
        }

        $derived = $this->snapshots($budgets);
        foreach ($budgets as $budget) {
            $key = (int) $budget->getKey();
            if (! isset($derived[$key])) {
                continue;
            }

            foreach ($derived[$key] as $column => $value) {
                $budget->setAttribute($column, $value);
            }
        }

        return $budgets;
    }

    /** @return array{total_allocated: string, total_spent: string, total_committed: string, available: string} */
    public function snapshot(Budget $budget): array
    {
        $snapshots = $this->snapshots(collect([$budget]));

        return $snapshots[(int) $budget->getKey()] ?? [
            'total_allocated' => (string) $budget->total_allocated,
            'total_spent' => (string) $budget->total_spent,
            'total_committed' => (string) $budget->total_committed,
            'available' => $budget->available,
        ];
    }

    /**
     * Return the derived actual for each live line in a fiscal year.
     *
     * @return array<int, string> keyed by budget_line_items.id
     */
    public function lineActualsForFiscalYear(int $fiscalYearId): array
    {
        $lineRows = $this->liveLineRows([$fiscalYearId]);
        if ($lineRows === []) {
            return [];
        }

        $actuals = $this->postedActuals([$fiscalYearId]);
        $accountPools = $this->accountPools($lineRows);
        $accountCounts = $this->accountCounts($lineRows);
        $result = [];

        foreach ($lineRows as $row) {
            $pool = $accountPools[$this->accountKey((int) $row->fiscal_year_id, (int) $row->account_id)] ?? Money::zero();
            $actual = $actuals[$this->accountKey((int) $row->fiscal_year_id, (int) $row->account_id)] ?? Money::zero();
            $share = Money::isZero($pool)
                ? Money::div($actual, (string) ($accountCounts[$this->accountKey((int) $row->fiscal_year_id, (int) $row->account_id)] ?? 1), Money::INNER)
                : Money::mul($actual, Money::div((string) $row->annual_total, $pool, Money::INNER));
            $result[(int) $row->line_id] = Money::round2($share);
        }

        return $result;
    }

    /**
     * Persist the current derived snapshot for live budgets in a fiscal year.
     * This keeps legacy readers and exports coherent after a sync completes.
     */
    public function refreshHeadersForFiscalYear(int $fiscalYearId): int
    {
        $budgets = Budget::query()
            ->with('lineItems')
            ->where('fiscal_year_id', $fiscalYearId)
            ->whereIn('status', self::LIVE_STATUSES)
            ->get();

        $this->hydrate($budgets);
        foreach ($budgets as $budget) {
            DB::table('budgets')->where('id', $budget->getKey())->update([
                'total_allocated' => $budget->total_allocated,
                'total_spent' => $budget->total_spent,
                'total_committed' => $budget->total_committed,
                'updated_at' => now(),
            ]);
        }

        return $budgets->count();
    }

    /** @return array{allocated: string, spent: string, committed: string, available: string, utilization_pct: float} */
    public function dashboardTotals(?FiscalYear $fiscalYear = null): array
    {
        if (! $fiscalYear) {
            return [
                'allocated' => Money::zero(),
                'spent' => Money::zero(),
                'committed' => Money::zero(),
                'available' => Money::zero(),
                'utilization_pct' => 0.0,
            ];
        }

        $budgets = Budget::query()
            ->with('lineItems')
            ->where('fiscal_year_id', $fiscalYear->getKey())
            ->whereIn('status', self::LIVE_STATUSES)
            ->get();
        $this->hydrate($budgets);

        $allocated = Money::zero();
        $spent = Money::zero();
        $committed = Money::zero();
        foreach ($budgets as $budget) {
            $allocated = Money::add($allocated, (string) $budget->total_allocated);
            $spent = Money::add($spent, (string) $budget->total_spent);
            $committed = Money::add($committed, (string) $budget->total_committed);
        }
        $available = Money::sub(Money::sub($allocated, $spent), $committed);
        $used = Money::add($spent, $committed);

        return [
            'allocated' => $allocated,
            'spent' => $spent,
            'committed' => $committed,
            'available' => $available,
            'utilization_pct' => Money::isZero($allocated)
                ? 0.0
                : round((float) Money::div($used, $allocated, Money::INNER) * 100, 1),
        ];
    }

    /** @return array<int, array{total_allocated: string, total_spent: string, total_committed: string, available: string}> */
    private function snapshots(Collection $budgets): array
    {
        $live = $budgets->filter(fn (Budget $budget): bool => in_array((string) $budget->status, self::LIVE_STATUSES, true));
        if ($live->isEmpty()) {
            return [];
        }

        $fiscalYearIds = $live->pluck('fiscal_year_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($fiscalYearIds === []) {
            return [];
        }

        // Query every live line in the fiscal years so allocation denominators
        // include sibling department/company-wide budgets not in this page.
        $lineRows = $this->liveLineRows($fiscalYearIds);
        if ($lineRows === []) {
            return [];
        }

        $actuals = $this->postedActuals($fiscalYearIds);
        $committed = $this->committedTotals($fiscalYearIds);
        $accountPools = $this->accountPools($lineRows);
        $accountCounts = $this->accountCounts($lineRows);
        $budgetPools = $this->budgetPools($lineRows);
        $departmentPools = $this->departmentPools($lineRows);

        $linesByBudget = [];
        foreach ($lineRows as $row) {
            $linesByBudget[(int) $row->budget_id][] = $row;
        }

        $snapshots = [];
        foreach ($live as $budget) {
            $budgetId = (int) $budget->getKey();
            $rows = $linesByBudget[$budgetId] ?? [];
            if ($rows === []) {
                // Header-only historical rows have no source account mapping;
                // preserve their explicitly stored values until migrated.
                $snapshots[$budgetId] = [
                    'total_allocated' => (string) $budget->total_allocated,
                    'total_spent' => (string) $budget->total_spent,
                    'total_committed' => (string) $budget->total_committed,
                    'available' => $budget->available,
                ];
                continue;
            }

            $allocated = $budgetPools[$budgetId] ?? Money::zero();
            $spent = Money::zero();
            foreach ($rows as $row) {
                $accountKey = $this->accountKey((int) $row->fiscal_year_id, (int) $row->account_id);
                $pool = $accountPools[$accountKey] ?? Money::zero();
                $actual = $actuals[$accountKey] ?? Money::zero();
                $share = Money::isZero($pool)
                    ? Money::div($actual, (string) ($accountCounts[$accountKey] ?? 1), Money::INNER)
                    : Money::mul($actual, Money::div((string) $row->annual_total, $pool, Money::INNER));
                $spent = Money::add($spent, $share);
            }

            $departmentKey = $this->departmentKey((int) $budget->fiscal_year_id, $budget->department_id);
            $departmentPool = $departmentPools[$departmentKey] ?? Money::zero();
            $committedTotal = $committed[$departmentKey] ?? Money::zero();
            $budgetCommitted = Money::isZero($departmentPool)
                ? Money::zero()
                : Money::mul($committedTotal, Money::div($allocated, $departmentPool, Money::INNER));

            $spent = Money::round2($spent);
            $budgetCommitted = Money::round2($budgetCommitted);
            $snapshots[$budgetId] = [
                'total_allocated' => Money::round2($allocated),
                'total_spent' => $spent,
                'total_committed' => $budgetCommitted,
                'available' => Money::sub(Money::sub($allocated, $spent), $budgetCommitted),
            ];
        }

        return $snapshots;
    }

    /** @return list<object> */
    private function liveLineRows(array $fiscalYearIds): array
    {
        return DB::table('budget_line_items as li')
            ->join('budgets as b', 'b.id', '=', 'li.budget_id')
            ->whereIn('b.fiscal_year_id', $fiscalYearIds)
            ->whereIn('b.status', self::LIVE_STATUSES)
            ->select([
                'li.id as line_id', 'li.budget_id', 'li.account_id',
                'li.annual_total', 'b.fiscal_year_id', 'b.department_id',
            ])
            ->orderBy('li.id')
            ->get()
            ->all();
    }

    /** @return array<string, string> */
    private function postedActuals(array $fiscalYearIds): array
    {
        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('fiscal_years as fy', function ($join): void {
                $join->on('je.date', '>=', 'fy.start_date')
                    ->on('je.date', '<=', 'fy.end_date');
            })
            ->whereIn('fy.id', $fiscalYearIds)
            // Reversed originals keep their historical effect; the mirror
            // entry (a separate posted JE) nets them out from the reversal
            // date.
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereNull('je.deleted_at')
            ->whereNull('jel.deleted_at')
            ->selectRaw('fy.id AS fiscal_year_id, jel.account_id, COALESCE(SUM(jel.debit), 0) - COALESCE(SUM(jel.credit), 0) AS actual')
            ->groupBy('fy.id', 'jel.account_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $this->accountKey((int) $row->fiscal_year_id, (int) $row->account_id) => Money::round2((string) $row->actual),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private function committedTotals(array $fiscalYearIds): array
    {
        $billedTotals = DB::table('bills')
            ->whereNotNull('purchase_order_id')
            ->select('purchase_order_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_amount ELSE 0 END), 0) AS billed_total")
            ->groupBy('purchase_order_id');

        return DB::table('purchase_orders as po')
            ->join('purchase_requests as pr', 'pr.id', '=', 'po.purchase_request_id')
            ->join('fiscal_years as fy', function ($join): void {
                $join->on('po.date', '>=', 'fy.start_date')
                    ->on('po.date', '<=', 'fy.end_date');
            })
            ->leftJoinSub($billedTotals, 'billed', 'billed.purchase_order_id', '=', 'po.id')
            ->whereIn('fy.id', $fiscalYearIds)
            ->whereIn('po.status', self::COMMITTED_PO_STATUSES)
            ->whereNull('po.deleted_at')
            ->whereNull('pr.deleted_at')
            ->selectRaw("fy.id AS fiscal_year_id, pr.department_id, COALESCE(SUM(CASE WHEN po.total_amount - COALESCE(billed.billed_total, 0) > 0 THEN po.total_amount - COALESCE(billed.billed_total, 0) ELSE 0 END), 0) AS committed")
            ->groupBy('fy.id', 'pr.department_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $this->departmentKey((int) $row->fiscal_year_id, $row->department_id === null ? null : (int) $row->department_id) => Money::round2((string) $row->committed),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function budgetPools(array $lineRows): array
    {
        $pools = [];
        foreach ($lineRows as $row) {
            $key = (int) $row->budget_id;
            $pools[$key] = Money::add($pools[$key] ?? Money::zero(), (string) $row->annual_total);
        }

        return $pools;
    }

    /** @return array<string, string> */
    private function accountPools(array $lineRows): array
    {
        $pools = [];
        foreach ($lineRows as $row) {
            $key = $this->accountKey((int) $row->fiscal_year_id, (int) $row->account_id);
            $pools[$key] = Money::add($pools[$key] ?? Money::zero(), (string) $row->annual_total);
        }

        return $pools;
    }

    /** @return array<string, int> */
    private function accountCounts(array $lineRows): array
    {
        $counts = [];
        foreach ($lineRows as $row) {
            $key = $this->accountKey((int) $row->fiscal_year_id, (int) $row->account_id);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string, string> */
    private function departmentPools(array $lineRows): array
    {
        $pools = [];
        foreach ($lineRows as $row) {
            $key = $this->departmentKey((int) $row->fiscal_year_id, $row->department_id === null ? null : (int) $row->department_id);
            $pools[$key] = Money::add($pools[$key] ?? Money::zero(), (string) $row->annual_total);
        }

        return $pools;
    }

    private function accountKey(int $fiscalYearId, int $accountId): string
    {
        return $fiscalYearId.':'.$accountId;
    }

    private function departmentKey(int $fiscalYearId, ?int $departmentId): string
    {
        return $fiscalYearId.':'.($departmentId ?? 'company');
    }
}
