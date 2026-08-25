<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Support\BudgetConsumptionLevel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'draft' => ['submitted'],
        'submitted' => ['active'],
        // approved is retained for legacy rows created before the active
        // naming was introduced.
        'approved' => ['closed'],
        'active' => ['closed'],
        'closed' => [],
    ];

    /** @var array<int, string> */
    private const MONTHS = [
        'jan', 'feb', 'mar', 'apr', 'may', 'jun',
        'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly BudgetConsumptionService $consumption,
    ) {}

    /** Create a budget with validated line items in one transaction. */
    public function create(array $data, array $lineItems): Budget
    {
        return DB::transaction(function () use ($data, $lineItems): Budget {
            $lineItems = $this->normaliseLineItems($lineItems, (string) ($data['budget_type'] ?? ''));
            $data['total_allocated'] = $this->totalAllocated($lineItems);
            unset($data['line_items']);

            $budget = Budget::create($data);
            foreach ($lineItems as $lineItem) {
                BudgetLineItem::create(['budget_id' => $budget->id, ...$lineItem]);
            }

            return $budget->load(['fiscalYear', 'department', 'lineItems.account']);
        });
    }

    /**
     * Update the complete draft contract. A caller that supplies line_items
     * replaces the set atomically; omitted line_items leaves it unchanged.
     */
    public function updateDraft(Budget $budget, array $data, ?array $lineItems = null): Budget
    {
        return DB::transaction(function () use ($budget, $data, $lineItems): Budget {
            $locked = Budget::query()->lockForUpdate()->findOrFail($budget->getKey());
            $this->assertStatus($locked, 'draft');

            $attributes = array_intersect_key($data, array_flip([
                'fiscal_year_id', 'department_id', 'budget_type', 'name',
            ]));
            if (isset($attributes['fiscal_year_id'])) {
                FiscalYear::query()->findOrFail((int) $attributes['fiscal_year_id']);
            }

            if ($lineItems !== null) {
                $normalised = $this->normaliseLineItems($lineItems, (string) ($attributes['budget_type'] ?? $locked->budget_type));
                $attributes['total_allocated'] = $this->totalAllocated($normalised);
                $locked->lineItems()->delete();
                foreach ($normalised as $lineItem) {
                    BudgetLineItem::create(['budget_id' => $locked->id, ...$lineItem]);
                }
            } else {
                $locked->load('lineItems');
                $this->normaliseLineItems($locked->lineItems->map(fn (BudgetLineItem $line): array => $line->toArray())->all(), (string) ($attributes['budget_type'] ?? $locked->budget_type));
            }

            $locked->fill($attributes)->save();

            return $locked->fresh()->load(['fiscalYear', 'department', 'lineItems.account']);
        });
    }

    /** Submit a budget for approval. */
    public function submit(Budget $budget, int $userId): Budget
    {
        return DB::transaction(function () use ($budget, $userId): Budget {
            $locked = Budget::query()->lockForUpdate()->findOrFail($budget->getKey());
            $this->assertTransition($locked, 'submitted');
            $locked->load('lineItems');
            $this->normaliseLineItems($locked->lineItems->map(fn (BudgetLineItem $line): array => $line->toArray())->all(), (string) $locked->budget_type);
            $locked->forceFill([
                'status' => 'submitted',
                'submitted_by' => $userId,
                'submitted_at' => now(),
            ])->save();

            return $locked->fresh()->load(['fiscalYear', 'department', 'lineItems.account', 'submittedBy', 'approvedBy']);
        });
    }

    /** Approve a submitted budget and make it active. */
    public function approve(Budget $budget, int $userId): Budget
    {
        return DB::transaction(function () use ($budget, $userId): Budget {
            $locked = Budget::query()->lockForUpdate()->findOrFail($budget->getKey());
            $this->assertTransition($locked, 'active');
            if ((int) $locked->submitted_by === $userId) {
                throw new BusinessRuleException('The user who submitted a budget cannot approve the same budget.');
            }

            $locked->load('lineItems');
            $this->normaliseLineItems($locked->lineItems->map(fn (BudgetLineItem $line): array => $line->toArray())->all(), (string) $locked->budget_type);
            $locked->forceFill([
                'status' => 'active',
                'approved_by' => $userId,
                'approved_at' => now(),
            ])->save();

            return $locked->fresh()->load(['fiscalYear', 'department', 'lineItems.account', 'submittedBy', 'approvedBy']);
        });
    }

    /** Close an active (or legacy approved) budget. */
    public function close(Budget $budget): Budget
    {
        return $this->transition($budget, 'closed');
    }

    /** Check budget consumption level and return warning severity. */
    public function checkConsumption(Budget $budget): string
    {
        $this->consumption->hydrate(collect([$budget->loadMissing('lineItems')]));
        $warning = $this->settings->requiredFloat('budget.warning_ratio', 0, 1);
        $critical = $this->settings->requiredFloat('budget.critical_ratio', $warning, 1);
        $exhausted = $this->settings->requiredFloat('budget.exhausted_ratio', $critical);
        $overdrawn = $this->settings->requiredFloat('budget.overdrawn_ratio', $exhausted);

        return BudgetConsumptionLevel::classify(
            Money::add((string) $budget->total_spent, (string) $budget->total_committed),
            (string) $budget->total_allocated,
            compact('warning', 'critical', 'exhausted', 'overdrawn'),
        );
    }

    /** Get budget overview for a fiscal year, grouped by department. */
    public function overview(int $fiscalYearId): array
    {
        $budgets = Budget::with(['department', 'lineItems'])
            ->byFiscalYear($fiscalYearId)
            ->get();
        $this->consumption->hydrate($budgets);

        $totalAllocated = Money::zero();
        $totalSpent = Money::zero();
        $totalCommitted = Money::zero();
        foreach ($budgets as $budget) {
            $totalAllocated = Money::add($totalAllocated, (string) $budget->total_allocated);
            $totalSpent = Money::add($totalSpent, (string) $budget->total_spent);
            $totalCommitted = Money::add($totalCommitted, (string) $budget->total_committed);
        }

        $byDepartment = $budgets->groupBy(fn (Budget $budget) => $budget->department_id ?? 0)
            ->map(function (Collection $deptBudgets): array {
                $allocated = $this->sumMoney($deptBudgets, 'total_allocated');
                $spent = $this->sumMoney($deptBudgets, 'total_spent');
                $committed = $this->sumMoney($deptBudgets, 'total_committed');
                $used = Money::add($spent, $committed);

                return [
                    'department_id' => $deptBudgets->first()->department?->hash_id,
                    'department' => $deptBudgets->first()->department?->name ?? 'Company-wide',
                    'allocated' => $allocated,
                    'spent' => $spent,
                    'committed' => $committed,
                    'available' => Money::sub(Money::sub($allocated, $spent), $committed),
                    'pct' => Money::isZero($allocated) ? 0.0 : round((float) Money::div($used, $allocated, Money::INNER) * 100, 1),
                ];
            })->values()->all();

        $used = Money::add($totalSpent, $totalCommitted);

        return [
            'total_allocated' => $totalAllocated,
            'total_spent' => $totalSpent,
            'total_committed' => $totalCommitted,
            'total_available' => Money::sub(Money::sub($totalAllocated, $totalSpent), $totalCommitted),
            'utilization_pct' => Money::isZero($totalAllocated) ? 0.0 : round((float) Money::div($used, $totalAllocated, Money::INNER) * 100, 1),
            'by_department' => $byDepartment,
        ];
    }

    /** Get budget-vs-actual comparison for a fiscal year. */
    public function budgetVsActual(int $fiscalYearId): array
    {
        $budgets = Budget::with('lineItems.account', 'department')
            ->byFiscalYear($fiscalYearId)
            ->active()
            ->get();
        $actuals = $this->consumption->lineActualsForFiscalYear($fiscalYearId);

        $rows = [];
        foreach ($budgets as $budget) {
            foreach ($budget->lineItems as $line) {
                $budgeted = Money::round2((string) $line->annual_total);
                $actual = $actuals[(int) $line->getKey()] ?? Money::round2((string) $line->actual_total);
                $variance = Money::sub($budgeted, $actual);
                $rows[] = [
                    'budget_id' => $budget->hash_id,
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'budget_type' => $budget->budget_type,
                    'department' => $budget->department?->name ?? 'Company-wide',
                    'budgeted' => $budgeted,
                    'actual' => $actual,
                    'variance' => $variance,
                    'variance_pct' => Money::isZero($budgeted) ? 0.0 : round((float) Money::div($variance, $budgeted, Money::INNER) * 100, 1),
                ];
            }
        }

        $totalBudgeted = Money::zero();
        $totalActual = Money::zero();
        foreach ($rows as $row) {
            $totalBudgeted = Money::add($totalBudgeted, $row['budgeted']);
            $totalActual = Money::add($totalActual, $row['actual']);
        }

        return [
            'rows' => $rows,
            'total_budgeted' => $totalBudgeted,
            'total_actual' => $totalActual,
            'total_variance' => Money::sub($totalBudgeted, $totalActual),
        ];
    }

    public function getCurrentFiscalYear(): ?FiscalYear
    {
        return FiscalYear::query()->active()->current()->orderByDesc('year')->first();
    }

    /** @param Collection<int, Budget> $budgets */
    private function sumMoney(Collection $budgets, string $column): string
    {
        $sum = Money::zero();
        foreach ($budgets as $budget) {
            $sum = Money::add($sum, (string) $budget->{$column});
        }

        return $sum;
    }

    /** @return array<int, array<string, string|int>> */
    private function normaliseLineItems(array $lineItems, string $budgetType): array
    {
        if ($lineItems === []) {
            throw new BusinessRuleException('A budget must contain at least one line item.');
        }

        $accountIds = array_map(fn (array $line): int => (int) ($line['account_id'] ?? 0), $lineItems);
        if (count($accountIds) !== count(array_unique($accountIds)) || in_array(0, $accountIds, true)) {
            throw new BusinessRuleException('A budget may contain each account only once.');
        }

        $accounts = Account::query()
            ->whereIn('id', $accountIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($accounts->count() !== count($accountIds)) {
            throw new BusinessRuleException('Every budget line must reference an existing account.');
        }

        $hasChildren = Account::query()->whereIn('parent_id', $accountIds)->pluck('parent_id')->map(fn ($id): int => (int) $id)->all();
        foreach ($accountIds as $accountId) {
            /** @var Account $account */
            $account = $accounts->get($accountId);
            if (! $account->is_active) {
                throw new BusinessRuleException("Account {$account->code} is inactive and cannot receive a budget allocation.");
            }
            if (in_array($accountId, $hasChildren, true)) {
                throw new BusinessRuleException("Account {$account->code} is a parent account; choose a leaf account.");
            }

            $type = $account->type instanceof AccountType ? $account->type->value : (string) $account->type;
            $requiredType = $budgetType === 'capital' ? AccountType::Asset->value : AccountType::Expense->value;
            if ($type !== $requiredType) {
                throw new BusinessRuleException(sprintf(
                    'Account %s is not eligible for a %s budget; expected a %s account.',
                    $account->code,
                    $budgetType,
                    $requiredType,
                ));
            }
        }

        $normalised = [];
        foreach ($lineItems as $line) {
            $normalisedLine = ['account_id' => (int) $line['account_id']];
            foreach (self::MONTHS as $month) {
                $normalisedLine[$month] = $this->normaliseAmount($line[$month] ?? '0', "line_items.{$month}");
            }
            $normalised[] = $normalisedLine;
        }

        return $normalised;
    }

    private function normaliseAmount(mixed $value, string $field): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new BusinessRuleException("{$field} must be a non-negative decimal with at most two fractional digits.");
        }
        $whole = explode('.', $value, 2)[0];
        if (strlen(ltrim($whole, '0')) > 13) {
            throw new BusinessRuleException("{$field} exceeds the supported 15,2 currency precision.");
        }

        return Money::round2($value);
    }

    /** @param array<int, array<string, string|int>> $lineItems */
    private function totalAllocated(array $lineItems): string
    {
        $total = Money::zero();
        foreach ($lineItems as $line) {
            $lineTotal = Money::zero();
            foreach (self::MONTHS as $month) {
                $lineTotal = Money::add($lineTotal, (string) $line[$month]);
            }
            $total = Money::add($total, $lineTotal);
        }

        return $total;
    }

    private function transition(Budget $budget, string $to, array $attributes = []): Budget
    {
        return DB::transaction(function () use ($budget, $to, $attributes): Budget {
            $locked = Budget::query()->lockForUpdate()->findOrFail($budget->getKey());
            $this->assertTransition($locked, $to);
            $locked->forceFill(['status' => $to, ...$attributes])->save();

            return $locked->fresh()->load(['fiscalYear', 'department', 'lineItems.account', 'submittedBy', 'approvedBy']);
        });
    }

    private function assertTransition(Budget $budget, string $to): void
    {
        $from = (string) $budget->status;
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new BusinessRuleException("Budget cannot transition from {$from} to {$to}.");
        }
    }

    private function assertStatus(Budget $budget, string $status): void
    {
        if ((string) $budget->status !== $status) {
            throw new BusinessRuleException("Only {$status} budgets can be edited.");
        }
    }
}
