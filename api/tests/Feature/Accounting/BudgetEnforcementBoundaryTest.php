<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\BudgetEnforcementService;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tranche B / F-044 — the budget gate must decide on exact money.
 *
 * The regression: consumption was rounded to one decimal before being compared
 * against budget.exhausted_ratio = 1.00, so 99.95% became 100.0 and classified
 * `exhausted`. That stamped a false label which assertAcknowledged() then turned
 * into a hard block on PO/PR approval, in every enforcement mode.
 */
class BudgetEnforcementBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The factories carry the statuses this path requires: FiscalYearFactory
     * yields status=active (which BudgetService::getCurrentFiscalYear() needs,
     * or checkAvailability() short-circuits to 'ok') and BudgetFactory yields
     * status=approved (which Budget::scopeActive() needs). The factory's random
     * year need not be the current one — getCurrentFiscalYear() falls back to
     * the newest active year.
     */
    private function budgetFor(string $allocated, string $spent, string $committed): Department
    {
        $department = Department::factory()->create();
        $fiscalYear = FiscalYear::factory()->create();

        Budget::factory()->create([
            'fiscal_year_id'   => $fiscalYear->id,
            'department_id'    => $department->id,
            'budget_type'      => 'operating',
            'name'             => 'Boundary probe',
            'total_allocated'  => $allocated,
            'total_spent'      => $spent,
            'total_committed'  => $committed,
        ]);

        return $department;
    }

    private function level(Department $department, string $amount): string
    {
        [, $level, ] = app(BudgetEnforcementService::class)
            ->checkAvailability((int) $department->id, $amount);

        return $level;
    }

    public function test_the_99_95_percent_band_is_critical_not_exhausted(): void
    {
        // 999,000.00 already consumed of 1,000,000.00; a 500.00 request takes it
        // to 99.95%. The department genuinely has 1,000.00 available.
        $department = $this->budgetFor('1000000.00', '900000.00', '99000.00');

        $this->assertSame(
            'critical',
            $this->level($department, '500.00'),
            'A department at 99.95% must be critical (advisory), not exhausted (which blocks approval).',
        );
    }

    public function test_exactly_one_hundred_percent_is_exhausted(): void
    {
        $department = $this->budgetFor('1000000.00', '900000.00', '99500.00');

        $this->assertSame('exhausted', $this->level($department, '500.00'));
    }

    public function test_a_request_equal_to_available_is_not_overdrawn(): void
    {
        // available = 1,000.00 exactly; requesting exactly that is not overdrawn.
        $department = $this->budgetFor('1000000.00', '900000.00', '99000.00');

        $this->assertNotSame('overdrawn', $this->level($department, '1000.00'));
    }

    public function test_one_centavo_over_available_is_overdrawn(): void
    {
        $department = $this->budgetFor('1000000.00', '900000.00', '99000.00');

        [$canProceed, $level, ] = app(BudgetEnforcementService::class)
            ->checkAvailability((int) $department->id, '1000.01');

        $this->assertFalse($canProceed);
        $this->assertSame('overdrawn', $level);
    }

    public function test_a_zero_allocation_budget_is_exhausted_and_never_divides(): void
    {
        // NOTE the difference from the classifier's own unit test, which returns
        // 'ok' for a zero ceiling. checkAvailability guards on available <= 0
        // FIRST and returns 'exhausted' — existing semantics, deliberately
        // preserved: a budget with nothing in it is exhausted, not fine. The
        // classifier's zero-ceiling branch is therefore unreachable through this
        // path and exists so the classifier cannot divide by zero on its own.
        $department = $this->budgetFor('0.00', '0.00', '0.00');

        $this->assertSame('exhausted', $this->level($department, '500.00'));
    }

    public function test_two_budgets_for_one_department_sum_exactly(): void
    {
        // Cent values that drift when summed as floats.
        $department = $this->budgetFor('0.10', '0.00', '0.00');
        $fiscalYearId = (int) Budget::query()
            ->where('department_id', $department->id)
            ->value('fiscal_year_id');

        Budget::factory()->create([
            'fiscal_year_id'  => $fiscalYearId,
            'department_id'   => $department->id,
            'budget_type'     => 'operating',
            'name'            => 'Second budget',
            'total_allocated' => '0.20',
            'total_spent'     => '0.00',
            'total_committed' => '0.00',
        ]);

        // allocated totals 0.30; a 0.30 request is exactly 100% consumed.
        $this->assertSame('exhausted', $this->level($department, '0.30'));
        // 0.29 is 96.67% — critical, not exhausted.
        $this->assertSame('critical', $this->level($department, '0.29'));
    }

    public function test_check_consumption_agrees_with_the_enforcement_gate(): void
    {
        // Same figures through both decision paths must yield the same level.
        // checkConsumption looks at the budget as it stands, so the comparable
        // enforcement call is one with a zero amount.
        $department = $this->budgetFor('1000000.00', '900000.00', '99950.00');
        $budget = Budget::query()
            ->where('department_id', $department->id)
            ->firstOrFail();

        $service = app(BudgetService::class);

        $this->assertSame(
            $this->level($department, '0.00'),
            $service->checkConsumption($budget),
            'checkConsumption and checkAvailability must classify identical figures identically.',
        );
        $this->assertSame('critical', $service->checkConsumption($budget));
    }
}
