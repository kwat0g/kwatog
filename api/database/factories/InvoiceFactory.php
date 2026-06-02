<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 500, 50000);
        $vat      = round($subtotal * 0.12, 2);
        $total    = $subtotal + $vat;
        $date     = fake()->dateTimeBetween('-3 months', 'now');
        $dueDate  = (clone $date)->modify('+30 days');

        return [
            'invoice_number' => 'INV-' . now()->format('Ym') . '-' . fake()->unique()->numerify('####'),
            'customer_id'    => Customer::factory(),
            'date'           => $date->format('Y-m-d'),
            'due_date'       => $dueDate->format('Y-m-d'),
            'is_vatable'     => true,
            'subtotal'       => $subtotal,
            'vat_amount'     => $vat,
            'total_amount'   => $total,
            'amount_paid'    => 0,
            'balance'        => $total,
            'status'         => 'draft',
            'created_by'     => User::factory(),
        ];
    }
}
