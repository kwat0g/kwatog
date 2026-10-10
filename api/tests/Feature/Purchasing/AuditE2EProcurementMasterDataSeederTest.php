<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Database\Seeders\AuditE2EProcurementMasterDataSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditE2EProcurementMasterDataSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_audit_procurement_seed_creates_catalog_before_supplier_link(): void
    {
        $this->assertStringStartsWith('ogami_test_', DB::connection()->getDatabaseName());

        $previous = getenv('AUDIT_E2E_TESTING');
        putenv('AUDIT_E2E_TESTING=true');
        try {
            (new AuditE2EProcurementMasterDataSeeder())->run();
        } catch (ModelNotFoundException) {
            $this->fail('The audit procurement seed must create RM-001 before looking it up.');
        } finally {
            putenv($previous === false ? 'AUDIT_E2E_TESTING' : 'AUDIT_E2E_TESTING='.$previous);
        }

        $item = Item::query()->where('code', 'RM-001')->firstOrFail();
        $vendor = Vendor::query()->where('name', 'Audit E2E Resin Supplier')->firstOrFail();

        $this->assertDatabaseHas('approved_suppliers', [
            'item_id' => $item->id,
            'vendor_id' => $vendor->id,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
        ]);
    }
}
