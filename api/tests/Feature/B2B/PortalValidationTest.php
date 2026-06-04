<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalValidationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Supplier Portal ────────────────────────────────────────────

    private function makeSupplierUser(): SupplierPortalUser
    {
        $vendor = Vendor::factory()->create();

        return SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name'      => 'Test Supplier',
            'email'     => 'supplier_' . uniqid() . '@test.com',
            'password'  => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }

    // ─── Customer Portal ────────────────────────────────────────────

    private function makeCustomerUser(): CustomerPortalUser
    {
        $customer = Customer::factory()->create();

        return CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name'        => 'Test Customer',
            'email'       => 'customer_' . uniqid() . '@test.com',
            'password'    => bcrypt('Password1!'),
            'is_active'   => true,
        ]);
    }

    // ─── Test 1: Supplier submit-invoice — missing bill_number → 422 ─

    public function test_supplier_submit_invoice_returns_422_when_bill_number_missing(): void
    {
        $supplierUser = $this->makeSupplierUser();

        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $supplierUser->vendor_id,
        ]);

        $this->actingAs($supplierUser, 'supplier_portal')
            ->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
                // bill_number intentionally omitted
                'date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bill_number']);
    }

    // ─── Test 2: Customer complaint — missing severity + description + affected_quantity → 422 ─

    public function test_customer_create_complaint_returns_422_when_required_fields_missing(): void
    {
        $customerUser = $this->makeCustomerUser();

        $this->actingAs($customerUser, 'customer_portal')
            ->postJson('/api/v1/b2b/customer/complaints', [
                // severity, description, affected_quantity intentionally omitted
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['severity', 'description', 'affected_quantity']);
    }
}
