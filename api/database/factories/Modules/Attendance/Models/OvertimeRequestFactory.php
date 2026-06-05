<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OvertimeRequest>
 */
class OvertimeRequestFactory extends Factory
{
    protected $model = OvertimeRequest::class;

    public function definition(): array
    {
        return [
            'employee_id'     => Employee::factory(),
            'date'            => fake()->dateTimeBetween('-30 days', '+7 days')->format('Y-m-d'),
            'hours_requested' => fake()->randomElement(['1.0', '2.0', '3.0', '4.0']),
            'reason'          => fake()->sentence(),
            'status'          => OvertimeStatus::Pending->value,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => OvertimeStatus::Pending->value]);
    }

    public function approved(): static
    {
        return $this->state(['status' => OvertimeStatus::Approved->value]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => OvertimeStatus::Rejected->value]);
    }
}
