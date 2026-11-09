<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalInvitationPrefillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Mail::fake();
    }

    private function operator(): User
    {
        return User::factory()->withRole('finance_officer')->create(['is_active' => true]);
    }

    public function test_account_records_expose_contact_name_and_email_for_invitation_prefill(): void
    {
        $customer = Customer::factory()->create([
            'contact_person' => 'Honda Sales Contact',
            'email' => 'honda-sales@example.test',
        ]);
        $vendor = Vendor::factory()->create([
            'contact_person' => 'Supplier Contact',
            'email' => 'supplier@example.test',
        ]);
        $operator = $this->operator();

        $this->actingAs($operator)
            ->getJson('/api/v1/customers?is_active=1')
            ->assertOk()
            ->assertJsonPath('data.0.contact_person', 'Honda Sales Contact')
            ->assertJsonPath('data.0.email', 'honda-sales@example.test');

        $this->actingAs($operator)
            ->getJson('/api/v1/vendors?is_active=1')
            ->assertOk()
            ->assertJsonPath('data.0.contact_person', 'Supplier Contact')
            ->assertJsonPath('data.0.email', 'supplier@example.test');
    }

    public function test_invitation_can_designate_customer_and_supplier_signatories(): void
    {
        $operator = $this->operator();
        $customer = Customer::factory()->create();
        $vendor = Vendor::factory()->create();

        $this->actingAs($operator)
            ->postJson("/api/v1/b2b/portal-access/customers/{$customer->hash_id}/invite", [
                'name' => 'Customer Signer',
                'email' => 'customer-signer@example.test',
                'is_authorized_signatory' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_authorized_signatory', true);

        $this->actingAs($operator)
            ->postJson("/api/v1/b2b/portal-access/suppliers/{$vendor->hash_id}/invite", [
                'name' => 'Supplier Signer',
                'email' => 'supplier-signer@example.test',
                'is_authorized_signatory' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_authorized_signatory', true);

        $this->assertDatabaseHas('customer_portal_users', [
            'customer_id' => $customer->id,
            'email' => 'customer-signer@example.test',
            'is_authorized_signatory' => true,
        ]);
        $this->assertDatabaseHas('supplier_portal_users', [
            'vendor_id' => $vendor->id,
            'email' => 'supplier-signer@example.test',
            'is_authorized_signatory' => true,
        ]);
    }
}
