<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollPeriod>
 */
class PayrollPeriodFactory extends Factory
{
    protected $model = PayrollPeriod::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-6 months', '-1 month');
        $end   = (clone $start)->modify('+14 days');
        $pay   = (clone $end)->modify('+2 days');

        return [
            'period_start'         => $start->format('Y-m-d'),
            'period_end'           => $end->format('Y-m-d'),
            'payroll_date'         => $pay->format('Y-m-d'),
            'is_first_half'        => true,
            'is_thirteenth_month'  => false,
            'created_by'           => User::factory(),
        ];
    }

    /**
     * status / disbursement_status / disbursed_at / disbursed_by /
     * journal_entry_id are non-fillable. Factory rows write via forceFill.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (PayrollPeriod $period) {
            if (! $period->status) {
                $period->forceFill(['status' => 'draft']);
            }
        });
    }
}
