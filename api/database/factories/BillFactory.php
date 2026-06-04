<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bill>
 */
class BillFactory extends Factory
{
    protected $model = Bill::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 500, 50000);
        $vat      = round($subtotal * 0.12, 2);
        $total    = $subtotal + $vat;
        $date     = fake()->dateTimeBetween('-3 months', 'now');
        $dueDate  = (clone $date)->modify('+30 days');

        return [
            'bill_number'       => 'BILL-' . now()->format('Ym') . '-' . fake()->unique()->numerify('####'),
            'vendor_id'         => Vendor::factory(),
            'purchase_order_id' => null,
            'date'              => $date->format('Y-m-d'),
            'due_date'          => $dueDate->format('Y-m-d'),
            'is_vatable'        => true,
            'subtotal'          => $subtotal,
            'vat_amount'        => $vat,
            'total_amount'      => $total,
            'amount_paid'       => 0,
            'balance'           => $total,
            'status'            => 'unpaid',
            'journal_entry_id'  => null,
            'created_by'        => User::factory(),
            'remarks'           => null,
            'has_variances'             => null,
            'three_way_match_snapshot'  => null,
            'three_way_overridden'      => null,
        ];
    }
}
