<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Sprint 6 — Task 47.
 * 15 demo price agreements (a subset of the customer × product matrix). The
 * mapping models a real OEM supplier scenario: each automaker buys a few
 * specific parts at a negotiated rate. Effective_from is fiscal year start;
 * effective_to runs out 12 months later.
 */
class PriceAgreementSeeder extends Seeder
{
    /**
     * Map of customer-name → [partNumber => price].
     */
    private const MATRIX = [
        'Toyota Motor Philippines, Inc.' => [
            'WB-001' => 22.50,
            'WB-002' => 30.00,
            'PC-001' => 35.00,
            'BU-001' => 26.00,
        ],
        'Nissan Philippines, Inc.' => [
            'WB-001' => 23.00,
            'PC-002' => 35.50,
            'RC-001' => 47.00,
        ],
        'Honda Philippines, Inc.' => [
            'WB-002' => 31.00,
            'PC-001' => 35.50,
            'RC-002' => 70.00,
        ],
        'Suzuki Philippines, Inc.' => [
            'BB-001' => 28.00,
            'BU-001' => 26.50,
        ],
        'Yamaha Motor Philippines, Inc.' => [
            'BB-001' => 28.50,
            'WB-001' => 23.50,
            'RC-001' => 47.50,
        ],
    ];

    public function run(): void
    {
        $effectiveFrom = Carbon::now()->startOfYear();
        $effectiveTo   = $effectiveFrom->copy()->addYear()->subDay();

        $created = 0;
        foreach (self::MATRIX as $customerName => $rows) {
            $customer = Customer::where('name', $customerName)->first();
            if (! $customer) {
                $this->command?->warn("Customer '{$customerName}' not found — run CustomerSeeder first.");
                continue;
            }
            foreach ($rows as $partNumber => $price) {
                $product = Product::where('part_number', $partNumber)->first();
                if (! $product) {
                    $this->command?->warn("Product '{$partNumber}' not found — run ProductSeeder first.");
                    continue;
                }
                PriceAgreement::firstOrCreate(
                    [
                        'product_id'     => $product->id,
                        'customer_id'    => $customer->id,
                        'effective_from' => $effectiveFrom->toDateString(),
                    ],
                    [
                        'price'        => $price,
                        'effective_to' => $effectiveTo->toDateString(),
                    ]
                );
                $created++;
            }
        }

        $this->command?->info("Seeded {$created} price agreements.");
    }
}
