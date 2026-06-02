<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'employee_no'          => 'OGM-' . fake()->unique()->numerify('######'),
            'first_name'           => fake()->firstName(),
            'last_name'            => fake()->lastName(),
            'birth_date'           => fake()->dateTimeBetween('-50 years', '-22 years')->format('Y-m-d'),
            'gender'               => 'male',
            'civil_status'         => 'single',
            'nationality'          => 'Filipino',
            'department_id'        => Department::factory(),
            'position_id'          => Position::factory(),
            'employment_type'      => 'regular',
            'pay_type'             => 'monthly',
            'basic_monthly_salary' => 20000,
            'date_hired'           => fake()->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d'),
            'status'               => 'active',
        ];
    }
}
