<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\FinalPayService;
use App\Modules\Loans\Models\EmployeeLoan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B2 — the separation finalize gate must name a remedy that actually exists.
 *
 * The gate (correctly) refuses to finalize while a loan is on the books. Its
 * message said "Settle all loans or confirm deduction in the final pay
 * breakdown", but for a PENDING application a settlement payment is refused
 * (nothing was disbursed) and no "confirm deduction" action exists anywhere in
 * the codebase. The real escapes are state changes on the loan plus a
 * RE-RUN of final pay — final pay settles an active loan only at compute time,
 * so an approval that happens afterwards leaves the balance standing and the
 * gate refusing forever until HR recomputes.
 *
 * These tests pin the message and prove the prescribed remedy is reachable.
 */
class SeparationLoanDeadEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seedMinimumAccounts();
    }

    public function test_pending_loan_block_names_the_remedy_that_exists(): void
    {
        $employee = Employee::factory()->create();
        EmployeeLoan::factory()->create([
            'loan_no' => 'LN-202609-0001',
            'employee_id' => $employee->id,
            'loan_type' => 'company_loan',
            'balance' => '6000.00',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin())
            ->patchJson('/api/v1/hr/clearances/'.$this->completedClearance($employee)->hash_id.'/finalize')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outstanding_loans']);

        $message = (string) $response->json('errors.outstanding_loans.0');

        $this->assertStringContainsString('LN-202609-0001', $message);
        $this->assertStringContainsString('never disbursed', $message);
        $this->assertStringContainsString('cancel or reject', $message);
        // "Settle all loans" is impossible for a pending row — it must not be
        // the only instruction the operator is given.
        $this->assertStringNotContainsString('Settle all loans or confirm deduction', $message);
    }

    public function test_active_loan_block_names_the_recompute_remedy(): void
    {
        $employee = Employee::factory()->create();
        EmployeeLoan::factory()->create([
            'loan_no' => 'LN-202609-0002',
            'employee_id' => $employee->id,
            'loan_type' => 'company_loan',
            'balance' => '3000.00',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin())
            ->patchJson('/api/v1/hr/clearances/'.$this->completedClearance($employee)->hash_id.'/finalize')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outstanding_loans']);

        $message = (string) $response->json('errors.outstanding_loans.0');

        $this->assertStringContainsString('LN-202609-0002', $message);
        $this->assertStringContainsString('re-run Compute final pay', $message);
        // The remedy is bounded by what the payout can recover — a ₱0 payout
        // settles nothing, so the message must not promise otherwise.
        $this->assertStringContainsString('residue', $message);
    }

    /**
     * Pins that the prescribed remedy works: once the loan is active, a re-run
     * of final pay settles it from the payout and the clearance finalizes.
     */
    public function test_recomputing_final_pay_settles_an_active_loan_and_unblocks_finalize(): void
    {
        $employee = Employee::factory()->create(['basic_monthly_salary' => '20000.00', 'pay_type' => 'monthly']);
        // A real employment window: the payout must exceed the loan, because
        // compute() settles only what the payout can recover.
        $employee->forceFill(['date_hired' => '2024-01-01'])->save();
        $clearance = $this->completedClearance($employee, [
            'separation_date' => '2026-05-31',
            'final_pay_computed' => false,
        ]);
        $loan = EmployeeLoan::factory()->create([
            'loan_no' => 'LN-202609-0003',
            'employee_id' => $employee->id,
            'loan_type' => 'company_loan',
            'principal' => '3000.00',
            'monthly_amortization' => '1000.00',
            'balance' => '3000.00',
            'pay_periods_total' => 3,
            'pay_periods_remaining' => 3,
            'status' => 'active',
        ]);

        // compute() owns the settlement, and it only settles ACTIVE loans.
        // Real service (no mock) — compute() itself posts no JE; the seeded
        // accounts are what a later postJournalEntry would need.
        //
        // The payout must be non-zero for a settlement to happen at all: the
        // pool is min(loan balance, what the payout can recover). A leaver who
        // is current on payroll has last_salary_pro_rated = ₱0 (payroll already
        // paid those days), so the recoverable money here is the 13th-month
        // accrual.
        DB::table('thirteenth_month_accruals')->insert([
            'employee_id' => $employee->id,
            'year' => 2026,
            'total_basic_earned' => '120000.00',
            'accrued_amount' => '10000.00',
            'is_paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $computed = app(FinalPayService::class)->compute($clearance);
        $this->assertSame('3000.00', $computed->final_pay_breakdown['less_loan_balance']);
        $this->assertSame('0.00', (string) $loan->fresh()->balance, 'Final pay must settle the active loan it deducted.');

        // Finalize posts the JE; stub that so the test needs no full ledger.
        // (A full mock DOES skip the constructor, so it is registered only
        // after the real compute() above has run.)
        $this->mock(FinalPayService::class)
            ->shouldReceive('postJournalEntry')
            ->once()
            ->andReturn(new \App\Modules\Accounting\Models\JournalEntry());

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(200);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function completedClearance(Employee $employee, array $overrides = []): Clearance
    {
        return Clearance::factory()->create(array_merge([
            'employee_id' => $employee->id,
            'status' => ClearanceStatus::Completed->value,
            'final_pay_computed' => true,
        ], $overrides));
    }

    /** Accounts FinalPayService::postJournalEntry() requires. */
    private function seedMinimumAccounts(): void
    {
        $accounts = [
            ['code' => '6010', 'name' => 'Salaries & Wages Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '1020', 'name' => 'Cash in Bank', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '2100', 'name' => 'Loans Payable', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2070', 'name' => 'Accrued Expenses', 'type' => 'liability', 'normal_balance' => 'credit'],
        ];
        foreach ($accounts as $a) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $a['code']],
                array_merge($a, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
