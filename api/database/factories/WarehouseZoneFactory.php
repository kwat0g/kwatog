<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarehouseZone>
 */
class WarehouseZoneFactory extends Factory
{
    protected $model = WarehouseZone::class;

    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'name'         => 'Zone ' . fake()->unique()->bothify('?#'),
            'code'         => strtoupper(fake()->unique()->lexify('Z???')),
            'zone_type'    => 'raw_materials',
        ];
    }
}
