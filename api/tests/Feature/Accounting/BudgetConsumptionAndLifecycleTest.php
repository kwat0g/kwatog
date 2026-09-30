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
use App\Modules\Accounting\Services\FiscalYearService;
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

    public function test_budget_approval_requires_a_role_other_than_the_submitter_role(): void
    {
        $fiscalYear = $this->currentFiscalYear();
        $account = $this->expenseAccount();
        $service = app(BudgetService::class);
        $budget = $service->create([
            'fiscal_year_id' => $fiscalYear->id,
            'department_id' => null,
            'budget_type' => 'operating',
            'name' => 'SoD role test',
        ], [['account_id' => $account->id, 'jan' => '10.00']]);

        $submitter = User::factory()->withRole('finance_officer')->create();
        $sameRole = User::factory()->withRole('finance_officer')->create();
        $checker = User::factory()->withRole('vice_president')->create();
        $submitted = $service->submit($budget, $submitter->id);

        try {
            $service->approve($submitted, $sameRole->id);
            $this->fail('A second holder of the submitting role must not approve the budget.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('different role', $exception->getMessage());
        }

        $approved = $service->approve($submitted->fresh(), $checker->id);
        $this->assertSame('active', $approved->status);
    }

    public function test_submitted_budget_can_be_rejected_to_draft_with_a_reason(): void
    {
        $fiscalYear = $this->currentFiscalYear();
        $account = $this->expenseAccount();
        $service = app(BudgetService::class);
        $budget = $service->create([
            'fiscal_year_id' => $fiscalYear->id,
            'department_id' => null,
            'budget_type' => 'operating',
            'name' => 'Reject test',
        ], [['account_id' => $account->id, 'jan' => '10.00']]);

        $submitter = User::factory()->withRole('finance_officer')->create();
        $checker = User::factory()->withRole('vice_president')->create();
        $submitted = $service->submit($budget, $submitter->id);

        $rejected = $service->reject($submitted, $checker->id, 'Rework the Q3 spread.');
        $this->assertSame('draft', $rejected->status);
        $this->assertSame('Rework the Q3 spread.', $rejected->rejection_reason);
        $this->assertSame($checker->id, (int) $rejected->rejected_by);
        // Submitter history survives the return so the maker is still known.
        $this->assertSame($submitter->id, (int) $rejected->submitted_by);

        // A fresh submission clears the previous rejection round.
        $resubmitted = $service->submit($rejected, $submitter->id);
        $this->assertSame('submitted', $resubmitted->status);
        $this->assertNull($resubmitted->rejection_reason);

        try {
            $service->reject($resubmitted, $checker->id, '  ');
            $this->fail('Rejecting without a reason must be refused.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        $active = $service->approve($resubmitted->fresh(), $checker->id);
        try {
            $service->reject($active, $checker->id, 'Too late to send back.');
            $this->fail('Rejecting a non-submitted budget must be refused.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_only_draft_budgets_can_be_deleted(): void
    {
        $fiscalYear = $this->currentFiscalYear();
        $account = $this->expenseAccount();
        $service = app(BudgetService::class);
        $budget = $service->create([
            'fiscal_year_id' => $fiscalYear->id,
            'department_id' => null,
            'budget_type' => 'operating',
            'name' => 'Delete me',
        ], [['account_id' => $account->id, 'jan' => '10.00']]);
        $lineId = $budget->lineItems->first()->id;

        $service->deleteDraft($budget);
        $this->assertNull(Budget::query()->find($budget->id));
        $this->assertNull(BudgetLineItem::query()->find($lineId));

        $kept = $service->create([
            'fiscal_year_id' => $fiscalYear->id,
            'department_id' => null,
            'budget_type' => 'operating',
            'name' => 'Keep me',
        ], [['account_id' => $account->id, 'jan' => '10.00']]);
        $maker = User::factory()->create();
        $submitted = $service->submit($kept, $maker->id);
        try {
            $service->deleteDraft($submitted);
            $this->fail('Deleting a submitted budget must be refused.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNotNull(Budget::query()->find($kept->id));
    }

    public function test_budgets_require_an_active_fiscal_year(): void
    {
        $service = app(BudgetService::class);
        $account = $this->expenseAccount();
        $draftYear = FiscalYear::factory()->create([
            'year' => 2031,
            'status' => 'draft',
            'start_date' => '2031-01-01',
            'end_date' => '2031-12-31',
        ]);
        $payload = [
            'fiscal_year_id' => $draftYear->id,
            'department_id' => null,
            'budget_type' => 'operating',
            'name' => 'Wrong year',
        ];
        try {
            $service->create($payload, [['account_id' => $account->id, 'jan' => '10.00']]);
            $this->fail('Allocating to a draft fiscal year must be refused.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('active fiscal year', $exception->getMessage());
        }

        $liveYear = $this->currentFiscalYear();
        $budget = $service->create([
            'fiscal_year_id' => $liveYear->id,
            'department_id' => null,
            'budget_type' => 'operating',
            'name' => 'Year guard',
        ], [['account_id' => $account->id, 'jan' => '10.00']]);

        try {
            $service->updateDraft($budget, ['fiscal_year_id' => $draftYear->id]);
            $this->fail('Retargeting a draft to a non-active year must be refused.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        $maker = User::factory()->create();
        $pending = $service->create([
            'fiscal_year_id' => $liveYear->id,
            'department_id' => null,
            'budget_type' => 'operating',
            'name' => 'Year guard submit',
        ], [['account_id' => $account->id, 'jan' => '10.00']]);
        $liveYear->forceFill(['status' => 'closed'])->save();
        try {
            $service->submit($pending, $maker->id);
            $this->fail('Submitting into a closed year must be refused.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_live_duplicate_names_are_refused_but_closed_ones_are_not(): void
    {
        $service = app(BudgetService::class);
        $account = $this->expenseAccount();
        $fiscalYear = $this->currentFiscalYear();
        $department = Department::factory()->create();
        $make = fn (string $name, ?int $departmentId): Budget => $service->create([
            'fiscal_year_id' => $fiscalYear->id,
            'department_id' => $departmentId,
            'budget_type' => 'operating',
            'name' => $name,
        ], [['account_id' => $account->id, 'jan' => '10.00']]);

        $first = $make('Maintenance ops', $department->id);
        try {
            $make('maintenance OPS', $department->id);
            $this->fail('A second live budget with the same name must be refused.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('supplemental', $exception->getMessage());
        }

        // Same name, different department: allowed (distinct pool).
        $other = $make('Maintenance ops', null);
        $this->assertSame('draft', $other->fresh()->status);

        // Same name, different year: allowed.
        $nextYear = FiscalYear::factory()->create([
            'year' => 2032,
            'status' => 'active',
            'start_date' => '2032-01-01',
            'end_date' => '2032-12-31',
        ]);
        $next = $service->create([
            'fiscal_year_id' => $nextYear->id,
            'department_id' => $department->id,
            'budget_type' => 'operating',
            'name' => 'Maintenance ops',
        ], [['account_id' => $account->id, 'jan' => '10.00']]);
        $this->assertSame('draft', $next->fresh()->status);

        // After close, the name is reusable in the same pool.
        $first->forceFill(['status' => 'active'])->save();
        $service->close($first->fresh());
        $replacement = $make('Maintenance ops', $department->id);
        $this->assertSame('draft', $replacement->fresh()->status);

        // Renaming a draft onto a live name is refused (self excluded).
        try {
            $service->updateDraft($other, ['department_id' => $department->id, 'name' => 'Maintenance ops']);
            $this->fail('Renaming onto a live duplicate must be refused.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }
        $service->updateDraft($other, ['name' => 'Maintenance ops v2']);
        $this->assertSame('Maintenance ops v2', $other->fresh()->name);
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

    public function test_fiscal_year_lifecycle_rejects_overlaps_and_controls_activation_and_close(): void
    {
        $service = app(FiscalYearService::class);
        $draft = $service->create([
            'year' => 2030,
            'start_date' => '2030-01-01',
            'end_date' => '2030-12-31',
        ]);
        $this->assertSame('draft', $draft->status);

        $active = $service->activate($draft);
        $this->assertSame('active', $active->status);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('must not overlap');
        $service->create([
            'year' => 2031,
            'start_date' => '2030-12-01',
            'end_date' => '2031-01-31',
        ]);
    }

    private function currentFiscalYear(): FiscalYear
    {
        return FiscalYear::factory()->create([
            'year' => 2026,
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
