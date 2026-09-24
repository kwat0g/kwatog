<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use App\Modules\Loans\Services\LoanService;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Database\QueryException;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LN-02 — manual loan settlement outside payroll.
 *
 * LoanService::recordPayment had no route and no caller outside tests, so a
 * separating employee with residual balance could never clear the
 * separation-finalize loan gate. POST /loans/{loan}/payments exposes it,
 * gated on loans.write_off.
 */
class LoanSettlementRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function makeLoan(array $overrides = []): EmployeeLoan
    {
        return EmployeeLoan::factory()->create(array_merge([
            'principal'             => '1000.00',
            'interest_rate'         => '0.00',
            'monthly_amortization'  => '500.00',
            'total_paid'            => '0.00',
            'balance'               => '1000.00',
            'pay_periods_total'     => 2,
            'pay_periods_remaining' => 2,
        ], $overrides));
    }

    private function makeHrOfficer(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'hr_officer')->value('id'),
        ]);
    }

    private function pay(EmployeeLoan $loan, array $payload, ?User $as = null)
    {
        $body = array_merge([
            'amount'       => '250.00',
            'payment_date' => now()->toDateString(),
            'remarks'      => 'Cash settlement at the cashier',
        ], $payload);
        $idempotencyKey = $body['idempotency_key'] ?? 'loan-payment-'.uniqid();
        unset($body['idempotency_key']);

        return $this->actingAs($as ?? $this->makeHrOfficer())
            ->postJson("/api/v1/loans/{$loan->hash_id}/payments", $body, ['Idempotency-Key' => $idempotencyKey]);
    }

    // ─────────────────────────────────────────────────────────────
    // 1. Settles an active loan through the existing ledger
    // ─────────────────────────────────────────────────────────────

    public function test_manual_payment_decrements_balance_and_records_manual_payment(): void
    {
        $loan = $this->makeLoan();

        $response = $this->pay($loan, ['amount' => '250.00']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.balance', '750.00');
        $response->assertJsonPath('data.status', 'active');

        $loan->refresh();
        $this->assertSame('250.00', (string) $loan->total_paid);
        $this->assertSame('750.00', (string) $loan->balance);

        $this->assertDatabaseHas('loan_payments', [
            'loan_id'      => $loan->id,
            'amount'       => '250.00',
            'payment_type' => 'manual',
        ]);
        $payment = LoanPayment::query()->where('loan_id', $loan->id)->sole();
        $this->assertNull($payment->clearance_id, 'A manual payment is not linked to a clearance.');
    }

    public function test_payment_of_full_balance_marks_loan_paid(): void
    {
        $loan = $this->makeLoan();

        $this->pay($loan, ['amount' => '1000.00'])->assertStatus(201);

        $loan->refresh();
        $this->assertSame('0.00', (string) $loan->balance);
        $this->assertSame('paid', $loan->status->value);
        $this->assertNotNull($loan->end_date);
    }

    public function test_reconciliation_uses_the_complete_payment_ledger(): void
    {
        $loan = $this->makeLoan();
        LoanPayment::create([
            'loan_id' => $loan->id,
            'amount' => '250.00',
            'payment_date' => '2026-04-10',
            'payment_type' => 'manual',
            'remarks' => 'Recorded payment',
        ]);

        app(LoanService::class)->reconcileAggregates($loan);

        $this->assertSame('250.00', (string) $loan->fresh()->total_paid);
        $this->assertSame('750.00', (string) $loan->fresh()->balance);
        $this->assertSame('active', $loan->fresh()->status->value);
    }

    public function test_replaying_a_partial_final_pay_settlement_does_not_charge_it_twice(): void
    {
        $loan = $this->makeLoan();
        $clearance = Clearance::create([
            'clearance_no' => 'CLR-T-'.substr(uniqid(), -5),
            'employee_id' => $loan->employee_id,
            'separation_date' => '2026-04-15',
            'separation_reason' => SeparationReason::Resigned->value,
            'clearance_items' => [],
            'status' => ClearanceStatus::InProgress->value,
            'initiated_by' => $this->makeHrOfficer()->id,
        ]);
        $service = app(LoanService::class);

        $first = $service->recordPayment(
            $loan, '400.00', \App\Modules\Loans\Enums\LoanPaymentType::FinalPay,
            clearanceId: $clearance->id,
        );
        $replayed = $service->recordPayment(
            $loan->fresh(), '400.00', \App\Modules\Loans\Enums\LoanPaymentType::FinalPay,
            clearanceId: $clearance->id,
        );

        $this->assertSame($first->id, $replayed->id);
        $this->assertSame(1, LoanPayment::query()->where('loan_id', $loan->id)->count());
        $this->assertSame('400.00', (string) $loan->fresh()->total_paid);
        $this->assertSame('600.00', (string) $loan->fresh()->balance);
    }

    public function test_manual_payment_cannot_mutate_a_computed_payroll_loan_input(): void
    {
        $loan = $this->makeLoan();
        $period = PayrollPeriod::factory()->create([
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15',
            'status' => PayrollPeriodStatus::Computed->value,
        ]);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $loan->employee_id,
            'loan_deductions' => '250.00',
        ]);

        $this->pay($loan, [
            'amount' => '100.00',
            'payment_date' => '2026-04-10',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A computed payroll period contains this loan payment date. Correct or void payroll before recording another payment.');

        $this->assertSame(0, LoanPayment::query()->where('loan_id', $loan->id)->count());
        $this->assertSame('1000.00', (string) $loan->fresh()->balance);
    }

    public function test_database_rejects_two_open_loans_of_the_same_type_for_one_employee(): void
    {
        $loan = $this->makeLoan();
        $duplicate = EmployeeLoan::factory()->make([
            'employee_id' => $loan->employee_id,
            'loan_type' => $loan->loan_type->value,
        ]);

        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_payment_clearance_provenance_requires_an_existing_clearance(): void
    {
        $loan = $this->makeLoan();

        $this->expectException(QueryException::class);
        LoanPayment::create([
            'loan_id' => $loan->id,
            'clearance_id' => 999999,
            'amount' => '100.00',
            'payment_date' => '2026-04-15',
            'payment_type' => 'final_pay',
            'remarks' => 'Invalid clearance provenance',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Guards
    // ─────────────────────────────────────────────────────────────

    public function test_overpayment_is_refused(): void
    {
        $loan = $this->makeLoan(['balance' => '1000.00']);

        $this->pay($loan, ['amount' => '1500.00'])->assertStatus(422);

        $this->assertSame(0, LoanPayment::query()->where('loan_id', $loan->id)->count());
        $this->assertSame('1000.00', (string) $loan->fresh()->balance);
    }

    public function test_zero_amount_is_refused(): void
    {
        $loan = $this->makeLoan();

        $this->pay($loan, ['amount' => '0.00'])->assertStatus(422);

        $this->assertSame(0, LoanPayment::query()->where('loan_id', $loan->id)->count());
    }

    public function test_non_decimal_amount_fails_validation(): void
    {
        $loan = $this->makeLoan();

        $this->pay($loan, ['amount' => '-50.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
        $this->pay($loan, ['amount' => '10.999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
        $this->pay($loan, ['amount' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(0, LoanPayment::query()->where('loan_id', $loan->id)->count());
    }

    public function test_pending_loan_cannot_be_paid(): void
    {
        $loan = $this->makeLoan();
        $loan->forceFill(['status' => 'pending'])->save();

        $this->pay($loan, ['amount' => '100.00'])->assertStatus(422);

        $this->assertSame(0, LoanPayment::query()->where('loan_id', $loan->id)->count());
    }

    public function test_role_without_write_off_permission_is_refused(): void
    {
        $loan = $this->makeLoan();
        // department_head holds loans.view + loans.approve but NOT loans.write_off.
        $departmentHead = User::factory()->create([
            'role_id' => Role::where('slug', 'department_head')->value('id'),
        ]);

        $this->pay($loan, ['amount' => '250.00'], $departmentHead)->assertStatus(403);

        $this->assertSame(0, LoanPayment::query()->where('loan_id', $loan->id)->count());
    }

    public function test_unauthenticated_request_is_refused(): void
    {
        $loan = $this->makeLoan();

        $this->postJson("/api/v1/loans/{$loan->hash_id}/payments", [
            'amount'       => '250.00',
            'payment_date' => now()->toDateString(),
        ])->assertStatus(401);
    }
}
