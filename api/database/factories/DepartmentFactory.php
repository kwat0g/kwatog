<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'name'      => fake()->unique()->words(2, true),
            'code'      => strtoupper(fake()->unique()->lexify('????')),
            'is_active' => true,
        ];
    }
}
