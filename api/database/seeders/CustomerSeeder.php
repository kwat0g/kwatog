<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Models\Customer;
use Illuminate\Database\Seeder;

/**
 * Sprint 6 — Task 47 (introduces upstream customer rows the CRM needs).
 * Five demo customers per docs/SEEDS.md §15.
 *
 * Each carries a delivery (ship-to) address and a receiving contact: the demo
 * driver run sheet is the whole point of the outbound chain, and a customer
 * whose only address is "Philippines" leaves that page empty. These are demo
 * records — the gate-level detail is illustrative, not a scraped address.
 */
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            ['name' => 'Toyota Motor Philippines, Inc.', 'tin' => '000-111-222-000', 'payment_terms_days' => 30, 'credit_limit' => 5_000_000,
                'delivery_address' => 'Receiving Gate, Toyota Special Economic Zone, Santa Rosa, Laguna',
                'delivery_contact_person' => 'Receiving Clerk', 'delivery_contact_phone' => '049-500-0001'],
            ['name' => 'Nissan Philippines, Inc.',       'tin' => '000-111-333-000', 'payment_terms_days' => 30, 'credit_limit' => 3_500_000,
                'delivery_address' => 'Nissan Plant, Santa Rosa, Laguna',
                'delivery_contact_person' => 'Receiving Clerk', 'delivery_contact_phone' => '049-500-0002'],
            ['name' => 'Honda Philippines, Inc.',        'tin' => '000-111-444-000', 'payment_terms_days' => 30, 'credit_limit' => 4_000_000,
                'delivery_address' => 'Honda Plant, Tanauan, Batangas',
                'delivery_contact_person' => 'Receiving Clerk', 'delivery_contact_phone' => '043-500-0003'],
            ['name' => 'Suzuki Philippines, Inc.',       'tin' => '000-111-555-000', 'payment_terms_days' => 45, 'credit_limit' => 2_000_000,
                'delivery_address' => 'Suzuki Plant, Canlubang, Calamba, Laguna',
                'delivery_contact_person' => 'Receiving Clerk', 'delivery_contact_phone' => '049-500-0004'],
            ['name' => 'Yamaha Motor Philippines, Inc.', 'tin' => '000-111-666-000', 'payment_terms_days' => 45, 'credit_limit' => 2_500_000,
                'delivery_address' => 'Yamaha Plant, LIMA Estate, Malvar, Batangas',
                'delivery_contact_person' => 'Receiving Clerk', 'delivery_contact_phone' => '043-500-0005'],
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
                    // Chain 1 — the driver is sent here, not to 'Philippines'.
                    'delivery_address'        => $c['delivery_address'],
                    'delivery_contact_person' => $c['delivery_contact_person'],
                    'delivery_contact_phone'  => $c['delivery_contact_phone'],
                    'is_active'          => true,
                ]
            );
        }

        $this->command?->info('Seeded ' . count($customers) . ' demo customers.');
    }
}
