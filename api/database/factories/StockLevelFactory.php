<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLevel>
 */
class StockLevelFactory extends Factory
{
    protected $model = StockLevel::class;

    public function definition(): array
    {
        return [
            'item_id'            => Item::factory(),
            'location_id'        => WarehouseLocation::factory(),
            'quantity'           => 0,
            'reserved_quantity'  => 0,
            'weighted_avg_cost'  => 0,
            'lock_version'       => 0,
        ];
    }
}
