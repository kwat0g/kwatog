<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Modules\Loans\Enums\LoanPaymentType;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use App\Modules\Loans\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanPaymentSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_payment_reloads_authoritative_state_before_updating_loan(): void
    {
        $loan = EmployeeLoan::factory()->create([
            'principal' => '1000.00',
            'interest_rate' => '0.00',
            'monthly_amortization' => '500.00',
            'total_paid' => '0.00',
            'balance' => '1000.00',
            'pay_periods_total' => 2,
            'pay_periods_remaining' => 2,
        ]);
        $stale = $loan->fresh();

        // Simulate a committed ledger payment after the caller loaded its
        // route model. The denormalized row is updated as the concurrent
        // writer would have done.
        LoanPayment::create([
            'loan_id' => $loan->id,
            'amount' => '250.00',
            'payment_date' => now()->toDateString(),
            'payment_type' => LoanPaymentType::Manual->value,
            'remarks' => 'Concurrent payment before stale caller resumes',
        ]);
        $loan->forceFill([
            'total_paid' => '250.00',
            'balance' => '750.00',
            'pay_periods_remaining' => 2,
            'status' => LoanStatus::Active->value,
        ])->save();

        $payment = app(LoanService::class)->recordPayment(
            $stale,
            '100.00',
            LoanPaymentType::Manual,
            remarks: 'Stale model serialization regression',
        );

        $loan->refresh();
        $this->assertSame('100.00', (string) $payment->amount);
        $this->assertSame('350.00', (string) $loan->total_paid);
        $this->assertSame('650.00', (string) $loan->balance);
        $this->assertSame(1, $loan->pay_periods_remaining);
        $this->assertSame(LoanStatus::Active, $loan->status);
        $this->assertDatabaseCount('loan_payments', 2);
    }
}
