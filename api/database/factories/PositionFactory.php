<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        return [
            'title'         => fake()->jobTitle(),
            'department_id' => Department::factory(),
        ];
    }
}
