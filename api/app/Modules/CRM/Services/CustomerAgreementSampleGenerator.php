<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Builds fictional but structurally realistic terms for the public-facing sample download. */
final class CustomerAgreementSampleGenerator
{
    /** @return array<string, mixed> */
    public function generate(): array
    {
        $reference = Str::upper(Str::random(8));
        $firstNames = ['Alyssa', 'Camille', 'Daniel', 'Elena', 'Gabriel', 'Isabelle', 'Joaquin', 'Lianne', 'Mateo', 'Nadine', 'Rafael', 'Sofia'];
        $lastNames = ['Alcantara', 'Castillo', 'Cruz', 'Dela Rosa', 'Domingo', 'Garcia', 'Mendoza', 'Navarro', 'Reyes', 'Rivera', 'Santos', 'Villanueva'];
        $companyNames = ['Crestline Mobility', 'Harborlight Components', 'Meridian Vehicle Systems', 'Northstar Auto Parts', 'Silverline Manufacturing', 'Summit Transport Components'];
        $barangays = ['Canlalay', 'Pulong Santa Cruz', 'San Antonio', 'Santo Tomas', 'Tagapo', 'Tubigan'];
        $cities = ['Biñan', 'Cabuyao', 'Calamba', 'Santa Rosa'];
        $contactFirst = $firstNames[random_int(0, count($firstNames) - 1)];
        $contactMiddle = $this->fictionalMiddleName();
        $contactLast = $lastNames[random_int(0, count($lastNames) - 1)];
        $city = $cities[random_int(0, count($cities) - 1)];
        $barangay = $barangays[random_int(0, count($barangays) - 1)];
        $company = $companyNames[random_int(0, count($companyNames) - 1)];
        $startsOn = CarbonImmutable::today();

        return [
            'reference' => "DEMO-{$reference}",
            'customer_name' => "{$company} Demo {$reference} Corporation",
            'contact_person' => "{$contactFirst} {$contactMiddle} {$contactLast}",
            'contact_role' => ['Procurement Manager', 'Purchasing Officer', 'Supply Chain Manager', 'Sales Operations Director'][random_int(0, 3)],
            'contact_phone' => sprintf('+63 917 %03d %04d', random_int(200, 999), random_int(1000, 9999)),
            'contact_email' => strtolower("{$contactFirst}.{$contactLast}.{$reference}@sample.test"),
            'billing_address' => sprintf('Unit %s, Demo Commerce Avenue, Barangay %s, %s, Laguna', $reference, $barangay, $city),
            'delivery_address' => sprintf('Warehouse Gate %s, Demo Logistics Park, Barangay %s, %s, Laguna', $reference, $barangay, $city),
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $startsOn->addYear()->subDay()->toDateString(),
            'payment_terms_days' => [30, 45, 60][random_int(0, 2)],
            'payment_methods' => ['Bank transfer or company check', 'Bank transfer', 'Company check or bank transfer'][random_int(0, 2)],
            'shipping_payer' => ['Buyer', 'Seller'][random_int(0, 1)],
            'products' => [
                [
                    'part_number' => 'WB-001',
                    'name' => 'Wiper Bushing',
                    'minimum_order_quantity' => 5,
                    'maximum_order_quantity' => 250,
                    'warranty_label' => '12-month standard',
                    'duration_days' => 365,
                    'report_window_days' => 30,
                    'issues' => 'manufacturing defects and shipping damage',
                    'remedies' => 'repair or replacement',
                ],
                [
                    'part_number' => 'PC-001',
                    'name' => 'Pivot Cap Cover Type A',
                    'minimum_order_quantity' => 1,
                    'maximum_order_quantity' => 80,
                    'warranty_label' => '6-month limited',
                    'duration_days' => 180,
                    'report_window_days' => 14,
                    'issues' => 'manufacturing defects',
                    'remedies' => 'replacement of affected part',
                ],
            ],
        ];
    }

    private function fictionalMiddleName(): string
    {
        $consonants = 'BCDFGHJKLMNPRSTVW';
        $vowels = 'AEIOU';
        $name = '';
        for ($index = 0; $index < 3; $index++) {
            $name .= $consonants[random_int(0, strlen($consonants) - 1)];
            $name .= $vowels[random_int(0, strlen($vowels) - 1)];
        }

        return ucfirst(strtolower($name));
    }
}
