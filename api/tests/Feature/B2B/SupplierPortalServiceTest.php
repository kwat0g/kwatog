<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Models\ChainStepRun;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for SupplierPortalService — verifies row-level scoping,
 * PO acknowledgment, shipment update, and delivery schedule submission
 * through the HTTP layer so controllers + services are exercised together.
 */
class SupplierPortalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    /* ─── Helpers ────────────────────────────────────────────────── */

    private function makePortalUser(?Vendor $vendor = null): SupplierPortalUser
    {
        $vendor ??= Vendor::factory()->create();

        return SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name' => 'SupUser-'.substr(uniqid(), -5),
            'email' => 'su-'.uniqid().'@t.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }

    private function actAs(SupplierPortalUser $user): self
    {
        Sanctum::actingAs($user, ['*'], 'supplier_portal');

        return $this;
    }

    private function createBill(int $vendorId): Bill
    {
        $internalUser = User::factory()->create();

        return Bill::create([
            'bill_number' => 'BILL-T-'.substr(uniqid(), -5),
            'vendor_id' => $vendorId,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_vatable' => true,
            'subtotal' => '1000.00',
            'vat_amount' => '120.00',
            'total_amount' => '1120.00',
            'amount_paid' => '0.00',
            'balance' => '1120.00',
            'status' => 'unpaid',
            'created_by' => $internalUser->id,
        ]);
    }

    /* ─── Dashboard ──────────────────────────────────────────────── */

    public function test_dashboard_returns_own_data(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
        ])->forceFill(['status' => 'approved'])->save();

        PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
        ])->forceFill(['status' => 'sent'])->save();

        // Other vendor's PO — must NOT count.
        $otherVendor = Vendor::factory()->create();
        PurchaseOrder::factory()->create([
            'vendor_id' => $otherVendor->id,
        ])->forceFill(['status' => 'approved'])->save();

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/supplier/dashboard');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.open_po_count'));
    }

    /* ─── Purchase Orders ────────────────────────────────────────── */

    public function test_purchase_orders_scoped_to_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        PurchaseOrder::factory()->count(3)->create(['vendor_id' => $vendor->id]);

        $other = Vendor::factory()->create();
        PurchaseOrder::factory()->count(2)->create(['vendor_id' => $other->id]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/supplier/purchase-orders');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_purchase_order_detail_forbidden_for_other_vendor(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $userA = $this->makePortalUser($vendorA);

        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendorB->id]);

        $this->actAs($userA);

        $response = $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}");

        $response->assertStatus(403);
    }

    public function test_purchase_order_detail_succeeds_for_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);

        $this->actAs($user);

        $response = $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}");

        $response->assertOk();
    }

    /* ─── Acknowledge PO ─────────────────────────────────────────── */

    public function test_acknowledge_po_succeeds(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => 'approved'])->save();

        $this->actAs($user);

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/acknowledge", [
            'expected_delivery_date' => '2026-08-01',
        ]);

        $response->assertOk();
        $this->assertSame('sent', $po->fresh()->status->value);
        $this->assertTrue(ChainStepRun::query()
            ->where('chain', 'p2p')
            ->where('entity_type', 'purchase_order')
            ->where('entity_id', $po->id)
            ->where('step', 'sent')
            ->exists(), 'Supplier acknowledgement must publish the PO sent chain step.');
    }

    public function test_acknowledge_po_forbidden_for_other_vendor(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $userA = $this->makePortalUser($vendorA);

        $poB = PurchaseOrder::factory()->create(['vendor_id' => $vendorB->id]);
        $poB->forceFill(['status' => 'approved'])->save();

        $this->actAs($userA);

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$poB->hash_id}/acknowledge", [
            'expected_delivery_date' => '2026-08-01',
        ]);

        $response->assertStatus(403);
    }

    public function test_acknowledge_po_rejects_non_approved_state_without_sent_handoff(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => 'cancelled'])->save();

        $this->actAs($user);

        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/acknowledge", [
            'expected_delivery_date' => '2026-08-01',
        ])->assertStatus(422);

        $this->assertSame('cancelled', $po->fresh()->status->value);
        $this->assertFalse(ChainStepRun::query()
            ->where('chain', 'p2p')
            ->where('entity_type', 'purchase_order')
            ->where('entity_id', $po->id)
            ->where('step', 'sent')
            ->exists(), 'A rejected acknowledgement must not publish the PO sent chain step.');
    }

    public function test_submit_invoice_stages_unposted_draft_and_retries_idempotently(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $item = Item::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => 'sent'])->save();
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Resin Type A',
            'quantity' => '2.00',
            'unit' => 'kg',
            'unit_price' => '100.00',
            'total' => '200.00',
            'quantity_received' => '0.00',
        ]);
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id' => $vendor->id,
            'status' => 'accepted',
            'accepted_by' => User::factory()->create()->id,
            'accepted_at' => now(),
        ]);
        GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => '2.00',
            'quantity_accepted' => '2.00',
            'unit_cost' => '100.00',
        ]);

        $this->actAs($user);
        $payload = [
            'bill_number' => 'SUP-INV-001',
            'date' => '2026-08-10',
            'is_vatable' => false,
        ];

        $first = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", $payload);
        $first->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');

        $bill = Bill::query()->where('vendor_id', $vendor->id)->where('bill_number', 'SUP-INV-001')->firstOrFail();
        $this->assertSame('draft', $bill->status->value);
        $this->assertNull($bill->journal_entry_id);
        $this->assertSame($item->id, $bill->items()->firstOrFail()->item_id);

        $second = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", $payload);
        $second->assertStatus(201)
            ->assertJsonPath('data.id', $bill->hash_id);
        $this->assertSame(1, Bill::query()
            ->where('vendor_id', $vendor->id)
            ->where('bill_number', 'SUP-INV-001')
            ->count());
    }

    public function test_submit_invoice_rejects_bill_number_already_attached_to_another_po(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $item = Item::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Resin Type A',
            'quantity' => '1.00',
            'unit' => 'kg',
            'unit_price' => '100.00',
            'total' => '100.00',
            'quantity_received' => '0.00',
        ]);

        $otherPo = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $existing = $this->createBill($vendor->id);
        $existing->forceFill([
            'bill_number' => 'SUP-INV-CONFLICT',
            'purchase_order_id' => $otherPo->id,
        ])->save();

        $this->actAs($user);

        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-CONFLICT',
            'date' => '2026-08-10',
            'is_vatable' => false,
        ])->assertStatus(422);

        $this->assertSame($otherPo->id, $existing->fresh()->purchase_order_id);
        $this->assertSame(1, Bill::query()
            ->where('vendor_id', $vendor->id)
            ->where('bill_number', 'SUP-INV-CONFLICT')
            ->count());
    }

    /* ─── Shipment Update ────────────────────────────────────────── */

    public function test_shipment_update_succeeds(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);

        $this->actAs($user);

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipment-update", [
            'shipped_date' => '2026-07-10',
            'carrier' => 'Maersk',
            'tracking_number' => 'MAEU1234567',
            'estimated_arrival' => '2026-07-15',
            'notes' => 'Container sealed at origin.',
        ]);

        $response->assertOk();
        $fresh = $po->fresh();
        $this->assertSame('2026-07-15', $fresh->expected_delivery_date->toDateString());
        $this->assertStringContainsString('Shipped: 2026-07-10', $fresh->remarks);
        $this->assertStringContainsString('Maersk', $fresh->remarks);
        $this->assertStringContainsString('MAEU1234567', $fresh->remarks);
        $this->assertStringContainsString('Container sealed at origin.', $fresh->remarks);
    }

    public function test_shipment_update_rejects_terminal_po_without_mutation(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill([
            'status' => 'cancelled',
            'remarks' => 'Cancelled by Purchasing.',
        ])->save();

        $this->actAs($user);

        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipment-update", [
            'carrier' => 'DHL',
            'tracking_number' => 'DHL-001',
        ])->assertStatus(422);

        $fresh = $po->fresh();
        $this->assertSame('cancelled', $fresh->status->value);
        $this->assertSame('Cancelled by Purchasing.', $fresh->remarks);
    }

    public function test_shipment_update_forbidden_for_other_vendor(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $userA = $this->makePortalUser($vendorA);

        $poB = PurchaseOrder::factory()->create(['vendor_id' => $vendorB->id]);

        $this->actAs($userA);

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$poB->hash_id}/shipment-update", [
            'carrier' => 'DHL',
        ]);

        $response->assertStatus(403);
    }

    /* ─── Invoices / Bills ───────────────────────────────────────── */

    public function test_invoices_scoped_to_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $this->createBill($vendor->id);
        $this->createBill($vendor->id);

        $other = Vendor::factory()->create();
        $this->createBill($other->id);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/supplier/invoices');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_supplier_invoice_pdf_renders_a_vendor_bill(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $bill = $this->createBill($vendor->id);

        $this->actAs($user);

        $this->get("/api/v1/b2b/supplier/invoices/{$bill->hash_id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_supplier_invoice_pdf_rejects_another_vendors_bill(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $otherBill = $this->createBill(Vendor::factory()->create()->id);

        $this->actAs($user);

        $this->get("/api/v1/b2b/supplier/invoices/{$otherBill->hash_id}/pdf")
            ->assertStatus(403);
    }

    public function test_invoice_detail_forbidden_for_other_vendor(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $userA = $this->makePortalUser($vendorA);

        $bill = $this->createBill($vendorB->id);

        $this->actAs($userA);

        $response = $this->getJson("/api/v1/b2b/supplier/invoices/{$bill->hash_id}");

        $response->assertStatus(403);
    }

    public function test_deliveries_are_scoped_and_use_the_supplier_allowlist(): void
    {
        $vendor = Vendor::factory()->create();
        $otherVendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $purchaseOrder = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $ownDelivery = GoodsReceiptNote::factory()->create([
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $purchaseOrder->id,
            'received_date' => '2026-08-12',
            'status' => 'accepted',
            'remarks' => 'Internal receiving remarks must not cross the portal boundary.',
            'rejected_reason' => 'Internal rejection reason must not cross the portal boundary.',
        ]);

        $otherPurchaseOrder = PurchaseOrder::factory()->create(['vendor_id' => $otherVendor->id]);
        $otherDelivery = GoodsReceiptNote::factory()->create([
            'vendor_id' => $otherVendor->id,
            'purchase_order_id' => $otherPurchaseOrder->id,
            'grn_number' => 'GRN-OTHER-0001',
        ]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/supplier/deliveries');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonMissing(['grn_number' => $otherDelivery->grn_number]);
        $row = $response->json('data.0');
        $this->assertSame([
            'id', 'grn_number', 'received_date', 'status', 'status_label', 'purchase_order',
        ], array_keys($row));
        $this->assertSame($ownDelivery->hash_id, $row['id']);
        $this->assertSame($ownDelivery->grn_number, $row['grn_number']);
        $this->assertSame('2026-08-12', $row['received_date']);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('Accepted', $row['status_label']);
        $this->assertSame([
            'id' => $purchaseOrder->hash_id,
            'po_number' => $purchaseOrder->po_number,
        ], $row['purchase_order']);

        foreach ([
            'numeric_id', 'vendor_id', 'received_by', 'accepted_by', 'qc_inspection_id',
            'journal_entry_id', 'rejected_reason', 'remarks', 'incoming_qc_handoff_message',
            'created_at', 'updated_at',
        ] as $sensitiveField) {
            $this->assertArrayNotHasKey($sensitiveField, $row);
        }
    }

    /* ─── Delivery Schedules ─────────────────────────────────────── */

    public function test_delivery_schedules_scoped_to_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);

        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);

        DeliverySchedule::create([
            'customer_id' => null, // supplier-submitted rows have no customer link
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $po->id,
            'month' => '2026-07',
            'status' => 'submitted',
            'lines' => [['item' => 'X', 'qty' => 100]],
        ]);

        // Other vendor's schedule — must NOT appear.
        $other = Vendor::factory()->create();
        $otherPo = PurchaseOrder::factory()->create(['vendor_id' => $other->id]);
        DeliverySchedule::create([
            'customer_id' => null, // supplier-submitted rows have no customer link
            'vendor_id' => $other->id,
            'purchase_order_id' => $otherPo->id,
            'month' => '2026-07',
            'status' => 'submitted',
            'lines' => [['item' => 'Y', 'qty' => 200]],
        ]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/supplier/delivery-schedules');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_store_delivery_schedule_rejects_other_vendors_purchase_order(): void
    {
        $vendor = Vendor::factory()->create();
        $otherVendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $otherPo = PurchaseOrder::factory()->create(['vendor_id' => $otherVendor->id]);

        $this->actAs($user);

        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $otherPo->hash_id,
            'month' => '2026-08',
            'lines' => [['product_name' => 'Relay Cover', 'quantity' => 500]],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('delivery_schedules', [
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $otherPo->id,
        ]);
    }

    public function test_store_delivery_schedule_is_idempotent_for_same_po_and_month(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);

        $this->actAs($user);

        $payload = [
            'purchase_order_id' => $po->hash_id,
            'month' => '2026-08',
            'lines' => [['product_name' => 'Relay Cover', 'quantity' => 500]],
        ];

        $first = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', $payload);
        $first->assertStatus(201);

        // A double-click or a retried request must not stack a second row.
        $second = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', $payload);
        $second->assertStatus(201);

        $this->assertDatabaseCount('delivery_schedules', 1);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_shipping_document_upload_is_idempotent_for_same_file(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $this->actAs($user);
        Storage::fake('local');

        $endpoint = "/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipping-documents";
        $payload = fn () => [
            'document_type' => 'packing_list',
            'file' => UploadedFile::fake()->create('packing-list.pdf', 120),
        ];

        $first = $this->postJson($endpoint, $payload());
        $first->assertStatus(201);

        // A double-click or a retried request with the same file must not
        // stack a second document row (or orphan a second stored file).
        $second = $this->postJson($endpoint, $payload());
        $second->assertStatus(201);

        $this->assertDatabaseCount('portal_shipping_documents', 1);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_shipping_document_download_scoped_to_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        // Create fixtures before acting as the portal user: HasAuditLog reads
        // Auth::id() (the portal guard after Sanctum::actingAs) and would hit
        // the audit_logs.users FK for portal-user ids.
        $other = Vendor::factory()->create();
        $this->actAs($user);
        Storage::fake('local');

        $upload = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipping-documents", [
            'document_type' => 'packing_list',
            'file' => UploadedFile::fake()->create('packing-list.pdf', 120),
        ]);
        $upload->assertStatus(201);
        $docId = $upload->json('data.id');

        // Own vendor can download the stored file (the SPA fetches this with
        // the portal Bearer token — never as a bare new-tab link).
        $download = $this->get("/api/v1/b2b/supplier/shipping-documents/{$docId}/download");
        $download->assertOk();
        $this->assertStringContainsString('packing-list.pdf', (string) $download->headers->get('content-disposition'));

        // Another vendor must be blocked from downloading it — the tenancy
        // middleware scopes PortalShippingDocument to the current vendor, so
        // the cross-tenant lookup 404s (no existence leak).
        $this->actAs($this->makePortalUser($other));
        $this->get("/api/v1/b2b/supplier/shipping-documents/{$docId}/download")->assertStatus(404);
    }

    /* ─── Auth guard ─────────────────────────────────────────────── */

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/b2b/supplier/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/b2b/supplier/purchase-orders')->assertStatus(401);
        $this->getJson('/api/v1/b2b/supplier/invoices')->assertStatus(401);
        $this->getJson('/api/v1/b2b/supplier/deliveries')->assertStatus(401);
    }
}
