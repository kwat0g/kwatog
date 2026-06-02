<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'name'      => fake()->words(2, true) . ' Warehouse',
            'code'      => strtoupper(fake()->unique()->lexify('WH-??')),
            'is_active' => true,
        ];
    }
}
