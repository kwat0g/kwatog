<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use Database\Seeders\RolePermissionSeeder;
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
        return $this->actingAs($as ?? $this->makeHrOfficer())
            ->postJson("/api/v1/loans/{$loan->hash_id}/payments", array_merge([
                'amount'       => '250.00',
                'payment_date' => now()->toDateString(),
                'remarks'      => 'Cash settlement at the cashier',
            ], $payload));
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
