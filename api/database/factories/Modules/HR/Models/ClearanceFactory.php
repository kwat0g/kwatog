<?php

declare(strict_types=1);

namespace Database\Factories\Modules\HR\Models;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Clearance>
 */
class ClearanceFactory extends Factory
{
    protected $model = Clearance::class;

    public function definition(): array
    {
        return [
            'clearance_no'      => 'CLR-' . fake()->unique()->numerify('######'),
            'employee_id'       => Employee::factory(),
            'separation_date'   => fake()->dateTimeBetween('+7 days', '+30 days')->format('Y-m-d'),
            'separation_reason' => SeparationReason::Resigned->value,
            'clearance_items'   => [],
            'final_pay_computed'=> false,
            'status'            => ClearanceStatus::InProgress->value,
            'initiated_by'      => User::factory(),
        ];
    }
}
