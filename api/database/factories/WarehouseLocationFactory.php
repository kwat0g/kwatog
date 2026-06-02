<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarehouseLocation>
 */
class WarehouseLocationFactory extends Factory
{
    protected $model = WarehouseLocation::class;

    public function definition(): array
    {
        return [
            'zone_id'          => WarehouseZone::factory(),
            'code'             => strtoupper(fake()->unique()->bothify('LOC-##??')),
            'is_active'        => true,
            'current_quantity' => 0,
            'is_blocked'       => false,
        ];
    }
}
