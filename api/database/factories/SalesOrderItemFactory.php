<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrderItem>
 */
class SalesOrderItemFactory extends Factory
{
    protected $model = SalesOrderItem::class;

    public function definition(): array
    {
        $qty       = fake()->randomFloat(2, 10, 500);
        $unitPrice = fake()->randomFloat(2, 5, 200);

        return [
            'sales_order_id'     => SalesOrder::factory(),
            'product_id'         => Product::factory(),
            'quantity'           => $qty,
            'unit_price'         => $unitPrice,
            'total'              => round($qty * $unitPrice, 2),
            'quantity_delivered' => 0,
            'delivery_date'      => now()->addDays(30)->toDateString(),
        ];
    }
}
