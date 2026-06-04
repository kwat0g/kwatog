<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\CRM\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'part_number'    => strtoupper(fake()->unique()->bothify('PT-##??-##')),
            'name'           => fake()->words(3, true),
            'description'    => null,
            'unit_of_measure' => 'pcs',
            'standard_cost'  => fake()->randomFloat(2, 10, 500),
            'is_active'      => true,
        ];
    }
}
