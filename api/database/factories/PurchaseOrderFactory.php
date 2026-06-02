<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'po_number'               => 'PO-' . now()->format('Ym') . '-' . fake()->unique()->numerify('####'),
            'vendor_id'               => Vendor::factory(),
            'date'                    => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'expected_delivery_date'  => fake()->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
            'subtotal'                => 0,
            'vat_amount'              => 0,
            'total_amount'            => 0,
            'is_vatable'              => true,
            'status'                  => 'draft',
            'requires_vp_approval'    => false,
            'current_approval_step'   => 0,
            'created_by'              => User::factory(),
        ];
    }
}
