<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\VendorSourcingService;
use App\Modules\Purchasing\Services\RequestForQuoteService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inactive vendor purchasing guard tests.
 *
 * Ensures that:
 * - Inactive or soft-deleted vendors cannot be used to create POs
 * - Draft POs for an active vendor cannot be submitted if vendor becomes inactive
 * - Draft POs for an active vendor cannot be approved if vendor becomes inactive
 * - VendorSourcingService excludes inactive vendors from candidate rankings
 * - RFQ cannot invite inactive vendors
 */
class InactiveVendorPurchasingTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderService $poService;

    private VendorSourcingService $sourcingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->poService = app(PurchaseOrderService::class);
        $this->sourcingService = app(VendorSourcingService::class);
    }

    /**
     * Test: PurchaseOrderService::create() refuses an inactive vendor.
     */
    public function test_po_service_create_refuses_inactive_vendor(): void
    {
        $vendor = Vendor::factory()->create(['is_active' => false]);
        $item = Item::factory()->create();
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id')]);

        // Create an approved PR with one line.
        $pr = PurchaseRequest::factory()->create(['status' => PurchaseRequestStatus::Approved]);
        $prItem = $pr->items()->create([
            'item_id' => $item->id,
            'description' => 'Test-Item-XX-T-' . substr(uniqid(), -5),
            'quantity' => '100.000',
            'unit' => 'pcs',
            'estimated_unit_price' => '50.00',
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('is inactive');

        $this->poService->create([
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $pr->id,
            'items' => [
                [
                    'item_id' => $item->id,
                    'purchase_request_item_id' => $prItem->id,
                    'description' => $prItem->description,
                    'quantity' => '100.000',
                    'unit' => 'pcs',
                    'unit_price' => '50.00',
                ],
            ],
        ], $user);
    }

    /**
     * Test: HTTP POST to create PO with inactive vendor returns 422 validation error.
     */
    public function test_http_post_create_po_with_inactive_vendor_returns_422(): void
    {
        $vendor = Vendor::factory()->create(['is_active' => false]);
        $item = Item::factory()->create();
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id')]);

        // Create an approved PR.
        $pr = PurchaseRequest::factory()->create(['status' => PurchaseRequestStatus::Approved]);
        $prItem = $pr->items()->create([
            'item_id' => $item->id,
            'description' => 'Test-Item-XX-T-' . substr(uniqid(), -5),
            'quantity' => '100.000',
            'unit' => 'pcs',
            'estimated_unit_price' => '50.00',
        ]);

        $this->actingAs($user);
        $response = $this->postJson('/api/v1/purchasing/purchase-orders', [
            'vendor_id' => app('hashids')->encode($vendor->id),
            'purchase_request_id' => app('hashids')->encode($pr->id),
            'items' => [
                [
                    'item_id' => app('hashids')->encode($item->id),
                    'purchase_request_item_id' => app('hashids')->encode($prItem->id),
                    'description' => $prItem->description,
                    'quantity' => '100.000',
                    'unit' => 'pcs',
                    'unit_price' => '50.00',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('vendor_id');
    }

    /**
     * Test: PO submit() refuses when vendor has been deactivated since draft creation.
     */
    public function test_po_submit_refuses_if_vendor_deactivated(): void
    {
        $vendor = Vendor::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id')]);
        $item = Item::factory()->create();

        // Create an approved PR.
        $pr = PurchaseRequest::factory()->create(['status' => PurchaseRequestStatus::Approved]);
        $prItem = $pr->items()->create([
            'item_id' => $item->id,
            'description' => 'Test-Item-XX-T-' . substr(uniqid(), -5),
            'quantity' => '100.000',
            'unit' => 'pcs',
            'estimated_unit_price' => '50.00',
        ]);

        // Create PO while vendor is active.
        $po = $this->poService->create([
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $pr->id,
            'items' => [
                [
                    'item_id' => $item->id,
                    'purchase_request_item_id' => $prItem->id,
                    'description' => $prItem->description,
                    'quantity' => '100.000',
                    'unit' => 'pcs',
                    'unit_price' => '50.00',
                ],
            ],
        ], $user);

        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);

        // Deactivate the vendor.
        $vendor->update(['is_active' => false]);

        // Try to submit the PO.
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('is inactive');

        $this->poService->submit($po->fresh(), $user);
    }

    /**
     * Test: PO approve() refuses when vendor has been deactivated.
     */
    public function test_po_approve_refuses_if_vendor_deactivated(): void
    {
        $vendor = Vendor::factory()->create(['is_active' => true]);
        $approver = User::factory()->create(['role_id' => Role::query()->where('slug', 'vice_president')->value('id')]);
        $creator = User::factory()->create(['role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id')]);
        $item = Item::factory()->create();

        // Create an approved PR.
        $pr = PurchaseRequest::factory()->create(['status' => PurchaseRequestStatus::Approved]);
        $prItem = $pr->items()->create([
            'item_id' => $item->id,
            'description' => 'Test-Item-XX-T-' . substr(uniqid(), -5),
            'quantity' => '100.000',
            'unit' => 'pcs',
            'estimated_unit_price' => '50.00',
        ]);

        // Create and submit PO while vendor is active.
        $po = $this->poService->create([
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $pr->id,
            'items' => [
                [
                    'item_id' => $item->id,
                    'purchase_request_item_id' => $prItem->id,
                    'description' => $prItem->description,
                    'quantity' => '100.000',
                    'unit' => 'pcs',
                    'unit_price' => '50.00',
                ],
            ],
        ], $creator);

        $po = $this->poService->submit($po->fresh(), $creator);
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $po->status);

        // Deactivate the vendor.
        $vendor->update(['is_active' => false]);

        // Try to approve the PO.
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('is inactive');

        $this->poService->approve($po->fresh(), $approver);
    }

    /**
     * Test: VendorSourcingService::candidatesForItem() excludes inactive vendors from all tiers.
     */
    public function test_vendor_sourcing_excludes_inactive_vendors_from_qualified_tier(): void
    {
        $item = Item::factory()->create();
        $activeVendor = Vendor::factory()->create(['is_active' => true]);
        $inactiveVendor = Vendor::factory()->create(['is_active' => false]);

        // Create approved supplier links for both vendors.
        ApprovedSupplier::create([
            'item_id' => $item->id,
            'vendor_id' => $activeVendor->id,
            'is_preferred' => true,
            'last_price' => '100.00',
        ]);
        ApprovedSupplier::create([
            'item_id' => $item->id,
            'vendor_id' => $inactiveVendor->id,
            'is_preferred' => false,
            'last_price' => '95.00',
        ]);

        $candidates = $this->sourcingService->candidatesForItem($item->id);

        // Should only have the active vendor.
        $this->assertCount(1, $candidates);
        $this->assertSame($activeVendor->id, $candidates[0]['vendor_id']);
        $this->assertSame($activeVendor->name, $candidates[0]['vendor_name']);
    }

    /**
     * Test: VendorSourcingService::candidatesForItem() excludes soft-deleted vendors.
     */
    public function test_vendor_sourcing_excludes_soft_deleted_vendors(): void
    {
        $item = Item::factory()->create();
        $activeVendor = Vendor::factory()->create(['is_active' => true]);
        $deletedVendor = Vendor::factory()->create(['is_active' => true]);

        // Create approved supplier links.
        ApprovedSupplier::create([
            'item_id' => $item->id,
            'vendor_id' => $activeVendor->id,
            'is_preferred' => true,
            'last_price' => '100.00',
        ]);
        ApprovedSupplier::create([
            'item_id' => $item->id,
            'vendor_id' => $deletedVendor->id,
            'is_preferred' => false,
            'last_price' => '95.00',
        ]);

        // Soft-delete the vendor.
        $deletedVendor->delete();

        $candidates = $this->sourcingService->candidatesForItem($item->id);

        // Should only have the active, non-deleted vendor.
        $this->assertCount(1, $candidates);
        $this->assertSame($activeVendor->id, $candidates[0]['vendor_id']);
    }

    /**
     * Test: VendorSourcingService::candidatesForItem() returns next active vendor from tier 4 (history).
     */
    public function test_vendor_sourcing_tier4_history_skips_inactive(): void
    {
        $item = Item::factory()->create();
        $activeVendor = Vendor::factory()->create(['is_active' => true]);
        $inactiveVendor = Vendor::factory()->create(['is_active' => false]);

        // Create PO history (tier 4) for both vendors.
        $poActive = PurchaseOrder::factory()->create(['vendor_id' => $activeVendor->id]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $poActive->id,
            'item_id' => $item->id,
            'description' => 'History-XX-T-' . substr(uniqid(), -5),
            'quantity' => '100.000',
            'unit_price' => '100.00',
            'total' => '10000.00',
            'unit' => 'pcs',
        ]);

        $poInactive = PurchaseOrder::factory()->create(['vendor_id' => $inactiveVendor->id]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $poInactive->id,
            'item_id' => $item->id,
            'description' => 'History-XX-T-' . substr(uniqid(), -5),
            'quantity' => '100.000',
            'unit_price' => '95.00',
            'total' => '9500.00',
            'unit' => 'pcs',
        ]);

        $candidates = $this->sourcingService->candidatesForItem($item->id);

        // Should only have the active vendor from tier 4.
        $this->assertCount(1, $candidates);
        $this->assertSame($activeVendor->id, $candidates[0]['vendor_id']);
        $this->assertSame('history', $candidates[0]['tier']);
    }

    /**
     * Test: RFQ invitation refuses inactive vendor.
     */
    public function test_rfq_invitation_refuses_inactive_vendor(): void
    {
        $inactiveVendor = Vendor::factory()->create(['is_active' => false]);
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id')]);

        // Create a PR for RFQ.
        $pr = PurchaseRequest::factory()->create([
            'status' => PurchaseRequestStatus::Approved,
            'sourcing_method' => 'rfq',
            'is_auto_generated' => true,
        ]);
        $pr->items()->create([
        // An RFQ line must be linked to an inventory item (an award becomes a PO line).
            'item_id' => Item::factory()->create()->id,
            'description' => 'RFQ-Item-XX-T-'.substr(uniqid(), -5),
            'quantity' => '100.000',
            'unit' => 'pcs',
            'estimated_unit_price' => '50.00',
        ]);

        $rfqService = app(RequestForQuoteService::class);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('is inactive');

        $rfqService->createFromPurchaseRequest($pr, [
            'title' => 'Test RFQ',
            'closes_at' => now()->addDays(7)->toDateTimeString(),
            'invitations' => [
                ['vendor_id' => $inactiveVendor->id],
            ],
        ], $user);
    }

    public function test_vendor_with_open_purchase_order_cannot_be_deleted(): void
    {
        $vendor = Vendor::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id')]);
        $item = Item::factory()->create();
        $pr = PurchaseRequest::factory()->create(['status' => PurchaseRequestStatus::Approved]);
        $prItem = $pr->items()->create([
            'item_id' => $item->id,
            'description' => 'Test-Item-XX-T-'.substr(uniqid(), -5),
            'quantity' => '10.000',
            'unit' => 'pcs',
            'estimated_unit_price' => '50.00',
        ]);
        $this->poService->create([
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $pr->id,
            'items' => [[
                'item_id' => $item->id,
                'purchase_request_item_id' => $prItem->id,
                'description' => $prItem->description,
                'quantity' => '10.000',
                'unit' => 'pcs',
                'unit_price' => '50.00',
            ]],
        ], $user);

        try {
            app(\App\Modules\Accounting\Services\VendorService::class)->delete($vendor->fresh());
            $this->fail('Deleting a vendor with an open PO must be refused.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('open purchase orders', $e->getMessage());
        }
        $this->assertNull($vendor->fresh()->deleted_at);
    }
}
