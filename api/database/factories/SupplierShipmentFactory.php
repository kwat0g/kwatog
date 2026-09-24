<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Models\SupplierShipment;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\B2B\Models\SupplierShipment>
 */
class SupplierShipmentFactory extends Factory
{
    protected $model = SupplierShipment::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'portal_user_id' => SupplierPortalUser::factory(),
            'shipped_date' => $this->faker->dateTime('-10 days'),
            'carrier' => $this->faker->word(),
            'tracking_number' => $this->faker->numerify('TRK###########'),
            'estimated_arrival' => $this->faker->dateTime('+5 days'),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
