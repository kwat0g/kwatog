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
            'is_final_pay_deduction' => false,
        ];
    }

    /**
     * `status` is non-fillable on the model (service-only mutation), so
     * factory rows write it via forceFill after creation. Tests can still
     * pass `'status' => '...'` to factory()->create() — the override is
     * captured here via the model's pre-save attributes.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (EmployeeLoan $loan) {
            if (! $loan->status) {
                $loan->forceFill(['status' => LoanStatus::Active->value]);
            }
        });
    }

    public function pending(): static
    {
        return $this
            ->state(['approval_chain_size' => 3])
            ->afterMaking(fn (EmployeeLoan $loan) =>
                $loan->forceFill(['status' => LoanStatus::Pending->value])
            );
    }
}
