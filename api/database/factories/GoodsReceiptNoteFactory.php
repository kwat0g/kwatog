<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceiptNote>
 */
class GoodsReceiptNoteFactory extends Factory
{
    protected $model = GoodsReceiptNote::class;

    public function definition(): array
    {
        return [
            'grn_number'        => 'GRN-' . now()->format('Ym') . '-' . fake()->unique()->numerify('####'),
            'purchase_order_id' => PurchaseOrder::factory(),
            'vendor_id'         => Vendor::factory(),
            'received_date'     => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'received_by'       => User::factory(),
            'status'            => 'pending_qc',
        ];
    }
}
