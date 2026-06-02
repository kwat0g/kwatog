<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 1000, 100000);
        $vat      = round($subtotal * 0.12, 2);

        return [
            'so_number'          => 'SO-' . now()->format('Ym') . '-' . fake()->unique()->numerify('####'),
            'customer_id'        => Customer::factory(),
            'date'               => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'subtotal'           => $subtotal,
            'vat_amount'         => $vat,
            'total_amount'       => $subtotal + $vat,
            'status'             => 'draft',
            'payment_terms_days' => 30,
            'created_by'         => User::factory(),
        ];
    }
}
