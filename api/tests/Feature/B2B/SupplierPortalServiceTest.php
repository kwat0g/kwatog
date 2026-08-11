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
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Resin Type A',
            'quantity' => '2.00',
            'unit' => 'kg',
            'unit_price' => '100.00',
            'total' => '200.00',
            'quantity_received' => '0.00',
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

    /* ─── Delivery Schedules ─────────────────────────────────────── */

    public function test_delivery_schedules_scoped_to_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $customer = Customer::factory()->create();

        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);

        DeliverySchedule::create([
            'customer_id' => $customer->id,
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
            'customer_id' => $customer->id,
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

    /* ─── Auth guard ─────────────────────────────────────────────── */

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/b2b/supplier/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/b2b/supplier/purchase-orders')->assertStatus(401);
        $this->getJson('/api/v1/b2b/supplier/invoices')->assertStatus(401);
        $this->getJson('/api/v1/b2b/supplier/deliveries')->assertStatus(401);
    }
}
