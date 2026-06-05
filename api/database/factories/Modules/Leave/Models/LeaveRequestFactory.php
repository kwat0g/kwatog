<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Leave\Models;

use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+7 days', '+14 days');
        $end   = (clone $start)->modify('+2 days');

        return [
            'leave_request_no' => 'LR-' . now()->format('Ym') . '-' . fake()->unique()->numerify('####'),
            'employee_id'      => Employee::factory(),
            'leave_type_id'    => fn () => LeaveType::firstOrCreate(
                ['code' => 'VL'],
                [
                    'name'            => 'Vacation Leave',
                    'default_balance' => 5.0,
                    'is_paid'         => true,
                    'is_active'       => true,
                ],
            )->id,
            'start_date' => $start->format('Y-m-d'),
            'end_date'   => $end->format('Y-m-d'),
            'days'       => 3.0,
            'reason'     => fake()->sentence(),
            'status'     => LeaveRequestStatus::PendingDept->value,
        ];
    }

    public function pendingDept(): static
    {
        return $this->state(['status' => LeaveRequestStatus::PendingDept->value]);
    }

    public function pendingHR(): static
    {
        return $this->state(['status' => LeaveRequestStatus::PendingHr->value]);
    }

    public function approved(): static
    {
        return $this->state(['status' => LeaveRequestStatus::Approved->value]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => LeaveRequestStatus::Rejected->value]);
    }
}
