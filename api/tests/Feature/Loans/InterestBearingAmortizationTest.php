<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Services\AmortizationService;
use Tests\TestCase;

class InterestBearingAmortizationTest extends TestCase
{
    private AmortizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AmortizationService();
    }

    public function test_zero_interest_generates_equal_payments(): void
    {
        $schedule = $this->service->generateWithInterest('10000.00', '0.00', 10);

        $this->assertCount(10, $schedule);
        foreach ($schedule as $row) {
            $this->assertEquals('0.00', $row['interest']);
        }
        $this->assertEquals('0.00', $schedule[9]['remaining_after']);
    }

    public function test_sss_loan_10_percent_24_months(): void
    {
        $schedule = $this->service->generateWithInterest('20000.00', '0.10', 24);

        $this->assertCount(24, $schedule);
        $this->assertEquals('0.00', $schedule[23]['remaining_after']);

        $totalPaid = array_sum(array_map(fn ($r) => (float) $r['amount'], $schedule));
        $totalInterest = array_sum(array_map(fn ($r) => (float) $r['interest'], $schedule));

        $this->assertGreaterThan(20000, $totalPaid);
        $this->assertGreaterThan(0, $totalInterest);

        $this->assertEqualsWithDelta(166.67, (float) $schedule[0]['interest'], 1.00);
    }

    public function test_pagibig_loan_10_5_percent(): void
    {
        $schedule = $this->service->generateWithInterest('50000.00', '0.105', 24);

        $this->assertCount(24, $schedule);
        $this->assertEquals('0.00', $schedule[23]['remaining_after']);

        $firstInterest = (float) $schedule[0]['interest'];
        $this->assertEqualsWithDelta(437.50, $firstInterest, 1.00);
    }

    public function test_monthly_amortization_with_interest(): void
    {
        $monthly = $this->service->monthlyAmortizationWithInterest('20000.00', '0.10', 24);

        $this->assertGreaterThan('833.33', $monthly);
        $this->assertLessThan('1000.00', $monthly);
    }

    public function test_loan_type_enum_has_government_types(): void
    {
        $this->assertTrue(LoanType::SssLoan->isGovernment());
        $this->assertTrue(LoanType::PagibigLoan->isGovernment());
        $this->assertFalse(LoanType::CompanyLoan->isGovernment());
        $this->assertFalse(LoanType::CashAdvance->isGovernment());
    }

    public function test_loan_type_default_interest_rates(): void
    {
        $this->assertEquals('0.10', LoanType::SssLoan->defaultInterestRate());
        $this->assertEquals('0.105', LoanType::PagibigLoan->defaultInterestRate());
        $this->assertEquals('0.00', LoanType::CompanyLoan->defaultInterestRate());
    }

    public function test_declining_principal_in_schedule(): void
    {
        $schedule = $this->service->generateWithInterest('30000.00', '0.12', 12);

        for ($i = 1; $i < count($schedule); $i++) {
            $this->assertLessThanOrEqual(
                (float) $schedule[$i - 1]['remaining_after'],
                (float) $schedule[$i - 1]['remaining_after']
            );
            $this->assertGreaterThan(0, (float) $schedule[$i - 1]['principal']);
        }
    }

    public function test_single_period_loan(): void
    {
        $schedule = $this->service->generateWithInterest('5000.00', '0.12', 1);

        $this->assertCount(1, $schedule);
        $this->assertEquals('0.00', $schedule[0]['remaining_after']);
        $this->assertEquals('50.00', $schedule[0]['interest']);
        $this->assertEquals('5000.00', $schedule[0]['principal']);
    }
}
