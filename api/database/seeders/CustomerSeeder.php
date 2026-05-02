<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Models\Customer;
use Illuminate\Database\Seeder;

/**
 * Sprint 6 — Task 47 (introduces upstream customer rows the CRM needs).
 * Five demo customers per docs/SEEDS.md §15.
 */
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            ['name' => 'Toyota Motor Philippines, Inc.', 'tin' => '000-111-222-000', 'payment_terms_days' => 30, 'credit_limit' => 5_000_000],
            ['name' => 'Nissan Philippines, Inc.',       'tin' => '000-111-333-000', 'payment_terms_days' => 30, 'credit_limit' => 3_500_000],
            ['name' => 'Honda Philippines, Inc.',        'tin' => '000-111-444-000', 'payment_terms_days' => 30, 'credit_limit' => 4_000_000],
            ['name' => 'Suzuki Philippines, Inc.',       'tin' => '000-111-555-000', 'payment_terms_days' => 45, 'credit_limit' => 2_000_000],
            ['name' => 'Yamaha Motor Philippines, Inc.', 'tin' => '000-111-666-000', 'payment_terms_days' => 45, 'credit_limit' => 2_500_000],
        ];

        foreach ($customers as $c) {
            Customer::firstOrCreate(
                ['name' => $c['name']],
                [
                    'contact_person'     => 'Procurement Officer',
                    'email'              => null,
                    'phone'              => null,
                    'address'            => 'Philippines',
                    'tin'                => $c['tin'],
                    'credit_limit'       => $c['credit_limit'],
                    'payment_terms_days' => $c['payment_terms_days'],
                    'is_active'          => true,
                ]
            );
        }

        $this->command?->info('Seeded ' . count($customers) . ' demo customers.');
    }
}
