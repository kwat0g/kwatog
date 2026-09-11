<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Enums\SupplierListingStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Models\SupplierItemListing;
use App\Modules\Purchasing\Services\VendorSourcingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VendorSourcingService is the single "who can supply this, at what price" path
 * shared by PR prefill, the conversion modal, and the auto-converter. Before it,
 * only a *preferred* approved supplier counted, so an approved-but-not-preferred
 * vendor (or a listed one) left a PR unsourceable.
 */
class VendorSourcingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): VendorSourcingService
    {
        return app(VendorSourcingService::class);
    }

    private function approvedSupplier(Item $item, Vendor $vendor, bool $preferred, string $price): ApprovedSupplier
    {
        return ApprovedSupplier::create([
            'item_id' => $item->id,
            'vendor_id' => $vendor->id,
            'is_preferred' => $preferred,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
            'last_price' => $price,
            'lead_time_days' => 4,
        ]);
    }

    public function test_approved_but_not_preferred_supplier_is_suggested(): void
    {
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();
        $this->approvedSupplier($item, $vendor, false, '12.50');

        $this->assertSame($vendor->id, $this->service()->suggestVendorId($item->id));
        $this->assertSame('12.50', $this->service()->priceFor($item->id, $vendor->id));
    }

    public function test_preferred_supplier_ranks_first(): void
    {
        $item = Item::factory()->create();
        $other = Vendor::factory()->create();
        $preferred = Vendor::factory()->create();
        $this->approvedSupplier($item, $other, false, '20.00');
        $this->approvedSupplier($item, $preferred, true, '25.00');

        $candidates = $this->service()->candidatesForItem($item->id);
        $this->assertSame($preferred->id, $candidates[0]['vendor_id']);
        $this->assertSame(VendorSourcingService::TIER_PREFERRED, $candidates[0]['tier']);
        $this->assertTrue($candidates[0]['qualified']);
    }

    public function test_provisional_link_is_not_a_qualified_suggestion(): void
    {
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();
        ApprovedSupplier::create([
            'item_id' => $item->id,
            'vendor_id' => $vendor->id,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_PROVISIONAL,
            'last_price' => '9.00',
        ]);

        $this->assertNull($this->service()->suggestVendorId($item->id));
    }

    public function test_pending_listing_is_a_candidate_but_not_qualified(): void
    {
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();

        $listing = new SupplierItemListing([
            'vendor_id' => $vendor->id,
            'item_id' => $item->id,
            'price' => '40.00',
            'lead_time_days' => 3,
            'submitted_at' => now(),
        ]);
        $listing->status = SupplierListingStatus::Pending;
        $listing->save();

        $candidates = $this->service()->candidatesForItem($item->id);
        $this->assertCount(1, $candidates);
        $this->assertSame($vendor->id, $candidates[0]['vendor_id']);
        $this->assertSame(VendorSourcingService::TIER_LISTED, $candidates[0]['tier']);
        $this->assertFalse($candidates[0]['qualified']);
        $this->assertSame('40.00', $candidates[0]['price']);
    }

    public function test_previous_purchase_supplies_a_candidate(): void
    {
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();

        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.substr(uniqid(), -6),
            'vendor_id' => $vendor->id,
            'date' => now()->subDays(10)->toDateString(),
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total_amount' => '100.00',
            'is_vatable' => false,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Received->value])->save();
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'History line',
            'quantity' => '2.00',
            'unit' => 'pcs',
            'unit_price' => '50.00',
            'total' => '100.00',
        ]);

        $candidates = $this->service()->candidatesForItem($item->id);
        $this->assertSame($vendor->id, $candidates[0]['vendor_id']);
        $this->assertSame(VendorSourcingService::TIER_HISTORY, $candidates[0]['tier']);
        $this->assertFalse($candidates[0]['qualified']);
        $this->assertSame('50.00', $candidates[0]['price']);
    }

    public function test_sourcing_endpoint_lists_candidates_for_a_pr(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();
        $this->approvedSupplier($item, $vendor, true, '25.00');

        $pr = PurchaseRequest::factory()->create(['requested_by' => $admin->id, 'department_id' => null]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();
        PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'description' => 'Source me',
            'quantity' => '3',
            'unit' => 'pcs',
            'estimated_unit_price' => '26.00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/purchasing/purchase-requests/'.$pr->hash_id.'/sourcing')
            ->assertOk();

        $line = $response->json('data.lines.0');
        $this->assertSame($vendor->hash_id, $line['suggested_vendor_id']);
        $this->assertSame('25.00', $line['suggested_unit_price']);
        $this->assertSame($vendor->hash_id, $line['candidates'][0]['id']);
        $this->assertTrue($line['candidates'][0]['qualified']);
    }
}
