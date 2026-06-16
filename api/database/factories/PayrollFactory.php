<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payroll>
 */
class PayrollFactory extends Factory
{
    protected $model = Payroll::class;

    public function definition(): array
    {
        $basicPay = fake()->randomFloat(2, 8000, 50000);

        return [
            'payroll_period_id'  => PayrollPeriod::factory(),
            'employee_id'        => Employee::factory(),
            'pay_type'           => 'monthly',
            'days_worked'        => 0,
            'basic_pay'          => $basicPay,
            'overtime_pay'       => 0,
            'night_diff_pay'     => 0,
            'holiday_pay'        => 0,
            'gross_pay'          => $basicPay,
            'leave_pay'          => 0,
            'sss_ee'             => 0,
            'sss_er'             => 0,
            'philhealth_ee'      => 0,
            'philhealth_er'      => 0,
            'pagibig_ee'         => 0,
            'pagibig_er'         => 0,
            'withholding_tax'    => 0,
            'loan_deductions'    => 0,
            'other_deductions'   => 0,
            'adjustment_amount'  => 0,
            'total_deductions'   => 0,
            'net_pay'            => $basicPay,
            'error_message'      => null,
            'computed_at'        => null,
        ];
    }
}
