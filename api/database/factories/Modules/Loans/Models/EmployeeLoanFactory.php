<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Loans\Models;

use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Models\EmployeeLoan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeLoan>
 */
class EmployeeLoanFactory extends Factory
{
    protected $model = EmployeeLoan::class;

    public function definition(): array
    {
        $principal = fake()->randomFloat(2, 1000, 10000);

        return [
            'loan_no'                => 'LN-' . fake()->unique()->numerify('######'),
            'employee_id'            => Employee::factory(),
            'loan_type'              => LoanType::CompanyLoan->value,
            'principal'              => $principal,
            'interest_rate'          => '0.00',
            'monthly_amortization'   => round($principal / 10, 2),
            'total_paid'             => '0.00',
            'balance'                => $principal,
            'pay_periods_total'      => 10,
            'pay_periods_remaining'  => 10,
            'approval_chain_size'    => 0,
            'status'                 => LoanStatus::Active->value,
            'is_final_pay_deduction' => false,
        ];
    }
}
