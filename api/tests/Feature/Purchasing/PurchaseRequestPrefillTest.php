<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-11 — PR submit/creation uses VendorSourcingService, so an item with a
 * qualified-but-not-preferred supplier is sourceable, its price is prefilled,
 * and editing a draft no longer discards an assigned vendor.
 */
class PurchaseRequestPrefillTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    public function test_submit_prefills_vendor_and_price_from_an_approved_supplier(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $user = $this->admin();
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();
        ApprovedSupplier::create([
            'item_id' => $item->id,
            'vendor_id' => $vendor->id,
            'is_preferred' => false,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
            'last_price' => '12.50',
        ]);

        $pr = PurchaseRequest::factory()->create(['requested_by' => $user->id, 'department_id' => null]);
        $line = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'description' => 'Needs sourcing',
            'quantity' => '4',
            'unit' => 'pcs',
        ]);

        app(PurchaseRequestService::class)->submit($pr->fresh(), $user);

        $line->refresh();
        $this->assertSame($vendor->id, (int) $line->suggested_vendor_id);
        $this->assertSame('12.50', (string) $line->estimated_unit_price);
    }

    public function test_editing_a_draft_pr_preserves_suggested_vendor(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = $this->admin();
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();

        $pr = PurchaseRequest::factory()->create(['requested_by' => $user->id, 'department_id' => null]);
        PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'suggested_vendor_id' => $vendor->id,
            'description' => 'Original',
            'quantity' => '1',
            'unit' => 'pcs',
            'estimated_unit_price' => '5.00',
        ]);

        $updated = app(PurchaseRequestService::class)->update($pr->fresh(), [
            'items' => [[
                'item_id' => $item->hash_id,
                'description' => 'Edited',
                'quantity' => '3',
                'unit' => 'pcs',
                'estimated_unit_price' => '6.00',
            ]],
        ], $user);

        $this->assertSame($vendor->id, (int) $updated->items->firstOrFail()->suggested_vendor_id);
    }
}
