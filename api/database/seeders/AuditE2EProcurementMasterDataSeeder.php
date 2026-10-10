<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AuditE2EProcurementMasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (app()->environment('production')
            || ! filter_var(env('AUDIT_E2E_TESTING', false), FILTER_VALIDATE_BOOL)
            || ! str_starts_with($database, 'ogami_test_')) {
            throw new RuntimeException('Audit-only procurement master data requires AUDIT_E2E_TESTING=true and an ogami_test_ database outside production.');
        }

        $this->call(InventoryItemSeeder::class);
        $this->call(PriceAgreementSeeder::class);
        $this->call(BomSeeder::class);

        $item = Item::query()->where('code', 'RM-001')->firstOrFail();
        $vendor = Vendor::query()->firstOrCreate(
            ['name' => 'Audit E2E Resin Supplier'],
            [
                'contact_person' => 'Audit Purchasing',
                'email' => 'audit-resin@ogami.test',
                'phone' => '09000000000',
                'payment_terms_days' => 30,
                'is_active' => true,
            ],
        );

        ApprovedSupplier::query()->firstOrCreate(
            ['item_id' => $item->id, 'vendor_id' => $vendor->id],
            [
                'is_preferred' => false,
                'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
                'lead_time_days' => 14,
                'last_price' => '120.00',
            ],
        );
    }
}
