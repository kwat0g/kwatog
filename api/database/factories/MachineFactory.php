<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\MRP\Models\Machine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Machine>
 */
class MachineFactory extends Factory
{
    protected $model = Machine::class;

    public function definition(): array
    {
        return [
            'machine_code'            => strtoupper(fake()->unique()->bothify('MC-###')),
            'name'                    => fake()->words(3, true) . ' Injection Molder',
            'tonnage'                 => fake()->randomElement([80, 100, 150, 200, 250, 300]),
            'machine_type'            => 'injection_molder',
            'operators_required'      => 1.0,
            'available_hours_per_day' => 16.0,
            'status'                  => 'idle',
            'current_work_order_id'   => null,
        ];
    }
}
