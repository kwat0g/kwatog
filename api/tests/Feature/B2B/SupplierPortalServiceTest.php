<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Models\PurchaseOrder;
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
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    /* ─── Helpers ────────────────────────────────────────────────── */

    private function makePortalUser(Vendor $vendor = null): SupplierPortalUser
    {
        $vendor ??= Vendor::factory()->create();

        return SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name'      => 'SupUser-' . substr(uniqid(), -5),
            'email'     => 'su-' . uniqid() . '@t.test',
            'password'  => bcrypt('Password1!'),
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
            'bill_number'  => 'BILL-T-' . substr(uniqid(), -5),
            'vendor_id'    => $vendorId,
            'date'         => now()->toDateString(),
            'due_date'     => now()->addDays(30)->toDateString(),
            'is_vatable'   => true,
            'subtotal'     => '1000.00',
            'vat_amount'   => '120.00',
            'total_amount' => '1120.00',
            'amount_paid'  => '0.00',
            'balance'      => '1120.00',
            'status'       => 'unpaid',
            'created_by'   => $internalUser->id,
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

    /* ─── Shipment Update ────────────────────────────────────────── */

    public function test_shipment_update_succeeds(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);

        $this->actAs($user);

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/shipment-update", [
            'carrier'           => 'Maersk',
            'tracking_number'   => 'MAEU1234567',
            'estimated_arrival' => '2026-07-15',
        ]);

        $response->assertOk();
        $fresh = $po->fresh();
        $this->assertStringContainsString('Maersk', $fresh->remarks);
        $this->assertStringContainsString('MAEU1234567', $fresh->remarks);
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
            'customer_id'       => $customer->id,
            'vendor_id'         => $vendor->id,
            'purchase_order_id' => $po->id,
            'month'             => '2026-07',
            'status'            => 'submitted',
            'lines'             => [['item' => 'X', 'qty' => 100]],
        ]);

        // Other vendor's schedule — must NOT appear.
        $other = Vendor::factory()->create();
        $otherPo = PurchaseOrder::factory()->create(['vendor_id' => $other->id]);
        DeliverySchedule::create([
            'customer_id'       => $customer->id,
            'vendor_id'         => $other->id,
            'purchase_order_id' => $otherPo->id,
            'month'             => '2026-07',
            'status'            => 'submitted',
            'lines'             => [['item' => 'Y', 'qty' => 200]],
        ]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/supplier/delivery-schedules');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
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
