<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\BudgetFiscalYearResolver;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BudgetConsumptionAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_and_enforcement_use_posted_gl_for_line_based_budgets(): void
    {
        $fiscalYear = $this->currentFiscalYear();
        $department = Department::factory()->create();
        $account = $this->expenseAccount();
        $budget = Budget::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'department_id' => $department->id,
            'status' => 'active',
            'total_allocated' => '100.00',
            'total_spent' => '0.00',
        ]);
        BudgetLineItem::create(['budget_id' => $budget->id, 'account_id' => $account->id, 'jan' => '100.00']);

        $entry = JournalEntry::create([
            'entry_number' => 'JE-BUDGET-'.uniqid(),
            'date' => '2026-04-01',
            'description' => 'Budget source test',
            'total_debit' => '40.00',
            'total_credit' => '0.00',
            'status' => 'draft',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'line_no' => 1,
            'debit' => '40.00',
            'credit' => '0.00',
        ]);
        DB::table('journal_entries')->where('id', $entry->id)->update(['status' => 'posted']);

        $overview = app(BudgetService::class)->overview($fiscalYear->id);
        $this->assertSame('100.00', $overview['total_allocated']);
        $this->assertSame('40.00', $overview['total_spent']);
        $this->assertSame('60.00', $overview['total_available']);

        [$canProceed, $level] = app(\App\Modules\Accounting\Services\BudgetEnforcementService::class)
            ->checkAvailability($department->id, '61.00', $fiscalYear->id);
        $this->assertFalse($canProceed);
        $this->assertSame('overdrawn', $level);
    }

    public function test_reversed_gl_actuals_remain_historical_until_the_reversal_date(): void
    {
        $fiscalYear = $this->currentFiscalYear();
        $account = $this->expenseAccount();
        $budget = Budget::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'status' => 'active',
            'total_allocated' => '100.00',
        ]);
        BudgetLineItem::create(['budget_id' => $budget->id, 'account_id' => $account->id, 'jan' => '100.00']);

        $maker = User::factory()->create();
        $poster = User::factory()->create();
        $journals = app(JournalEntryService::class);
        $cash = Account::create([
            'code' => 'C-'.substr(uniqid(), -6),
            'name' => 'Budget reversal cash',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        $entry = $journals->create([
            'date' => '2026-06-15',
            'description' => 'Budget historical reversal test',
            'lines' => [
                ['account_id' => $account->hash_id, 'debit' => '40.00', 'credit' => '0'],
                ['account_id' => $cash->hash_id, 'debit' => '0', 'credit' => '40.00'],
            ],
        ], $maker);
        $journals->post($entry, $poster);
        $this->assertSame('40.00', app(\App\Modules\Accounting\Services\BudgetService::class)
            ->overview($fiscalYear->id)['total_spent']);
        $journals->reverse($entry, $poster, \Carbon\Carbon::parse('2026-08-10'), 'Test reversal');

        $this->assertSame('0.00', app(\App\Modules\Accounting\Services\BudgetService::class)
            ->overview($fiscalYear->id)['total_spent']);
    }

    public function test_budget_lifecycle_is_locked_and_maker_checker_is_enforced(): void
    {
        $fiscalYear = $this->currentFiscalYear();
        $account = $this->expenseAccount();
        $budget = app(BudgetService::class)->create([
            'fiscal_year_id' => $fiscalYear->id,
            'department_id' => null,
            'budget_type' => 'operating',
            'name' => 'Lifecycle test',
        ], [['account_id' => $account->id, 'jan' => '10.00']]);
        $submitter = User::factory()->create();
        $approver = User::factory()->create();
        $service = app(BudgetService::class);

        try {
            $service->approve($budget, $approver->id);
            $this->fail('A draft budget must not be approved directly.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        $submitted = $service->submit($budget, $submitter->id);
        $this->assertSame('submitted', $submitted->status);

        $this->expectException(BusinessRuleException::class);
        $service->approve($submitted, $submitter->id);
    }

    public function test_commitments_are_derived_from_open_purchase_orders_and_bills(): void
    {
        $fiscalYear = $this->currentFiscalYear();
        $department = Department::factory()->create();
        $account = $this->expenseAccount();
        $budget = Budget::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'department_id' => $department->id,
            'status' => 'active',
            'total_allocated' => '100.00',
        ]);
        BudgetLineItem::create(['budget_id' => $budget->id, 'account_id' => $account->id, 'jan' => '100.00']);

        $request = PurchaseRequest::factory()->create([
            'department_id' => $department->id,
            'date' => '2026-03-01',
        ]);
        $request->forceFill(['status' => 'approved'])->save();
        $order = PurchaseOrder::factory()->create([
            'purchase_request_id' => $request->id,
            'date' => '2026-03-02',
            'total_amount' => '25.00',
        ]);
        $order->forceFill(['status' => 'approved'])->save();

        $this->assertSame('25.00', app(BudgetService::class)->overview($fiscalYear->id)['total_committed']);

        \App\Modules\Accounting\Models\Bill::factory()->create([
            'purchase_order_id' => $order->id,
            'total_amount' => '10.00',
            'status' => 'unpaid',
        ]);
        $this->assertSame('15.00', app(BudgetService::class)->overview($fiscalYear->id)['total_committed']);

        $order->forceFill(['status' => 'cancelled'])->save();
        $this->assertSame('0.00', app(BudgetService::class)->overview($fiscalYear->id)['total_committed']);
    }

    public function test_current_sync_target_rejects_missing_or_future_default_year(): void
    {
        FiscalYear::factory()->create([
            'status' => 'active',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
        ]);

        try {
            app(BudgetFiscalYearResolver::class)->resolve();
            $this->fail('A future-only active fiscal year must not be selected as current.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fiscal_year_id', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        app(BudgetFiscalYearResolver::class)->resolve(999999);
    }

    private function currentFiscalYear(): FiscalYear
    {
        return FiscalYear::factory()->create([
            'status' => 'active',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
    }

    private function expenseAccount(): Account
    {
        return Account::create([
            'code' => 'B-'.substr(uniqid(), -6),
            'name' => 'Budget test expense',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
    }
}
