<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'code'                   => strtoupper(fake()->unique()->bothify('ITM-##??')),
            'name'                   => fake()->words(3, true),
            'category_id'            => ItemCategory::factory(),
            'item_type'              => 'raw_material',
            'unit_of_measure'        => 'pcs',
            'standard_cost'          => 0,
            'reorder_method'         => 'fixed_quantity',
            'reorder_point'          => 0,
            'safety_stock'           => 0,
            'minimum_order_quantity' => 1,
            'lead_time_days'         => 7,
            'is_critical'            => false,
            'is_active'              => true,
        ];
    }
}
