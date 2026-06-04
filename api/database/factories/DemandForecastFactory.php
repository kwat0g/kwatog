<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\CRM\Models\Product;
use App\Modules\Forecasting\Models\DemandForecast;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandForecast>
 */
class DemandForecastFactory extends Factory
{
    protected $model = DemandForecast::class;

    public function definition(): array
    {
        return [
            'product_id'          => Product::factory(),
            'customer_id'         => null,
            'forecast_month'      => fake()->numberBetween(1, 12),
            'forecast_year'       => fake()->numberBetween(2024, 2026),
            'method'              => 'moving_avg',
            'forecasted_quantity' => fake()->randomFloat(2, 50, 500),
            'confidence_level'    => null,
            'actual_quantity'     => null,
            'variance'            => null,
            'created_by'          => null,
        ];
    }
}
