<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\SalesOrder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for CustomerPortalService — verifies row-level scoping,
 * dashboard aggregation, complaint creation, and delivery schedule submission
 * through the HTTP layer so controllers + services are exercised together.
 */
class CustomerPortalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    /* ─── Helpers ────────────────────────────────────────────────── */

    private function makePortalUser(Customer $customer = null): CustomerPortalUser
    {
        $customer ??= Customer::factory()->create();

        return CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name'        => 'CustUser-' . substr(uniqid(), -5),
            'email'       => 'cu-' . uniqid() . '@t.test',
            'password'    => bcrypt('Password1!'),
            'is_active'   => true,
        ]);
    }

    private function actAs(CustomerPortalUser $user): self
    {
        Sanctum::actingAs($user, ['*'], 'customer_portal');
        return $this;
    }

    /* ─── Dashboard ──────────────────────────────────────────────── */

    public function test_dashboard_returns_own_data(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        SalesOrder::factory()->create(['customer_id' => $customer->id, 'status' => 'confirmed']);
        SalesOrder::factory()->create(['customer_id' => $customer->id, 'status' => 'confirmed']);

        // Another customer's order — must NOT appear.
        $otherCustomer = Customer::factory()->create();
        SalesOrder::factory()->create(['customer_id' => $otherCustomer->id, 'status' => 'confirmed']);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/dashboard');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.open_so_count'));
    }

    /* ─── Sales Orders ───────────────────────────────────────────── */

    public function test_sales_orders_scoped_to_own_customer(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        SalesOrder::factory()->count(3)->create(['customer_id' => $customer->id]);

        // Other customer's orders
        $other = Customer::factory()->create();
        SalesOrder::factory()->count(2)->create(['customer_id' => $other->id]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/orders');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_sales_orders_filter_by_status(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        SalesOrder::factory()->create(['customer_id' => $customer->id, 'status' => 'confirmed']);
        SalesOrder::factory()->create(['customer_id' => $customer->id, 'status' => 'draft']);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/orders?status=confirmed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_sales_order_detail_forbidden_for_other_customer(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $userA = $this->makePortalUser($customerA);

        $soB = SalesOrder::factory()->create(['customer_id' => $customerB->id]);

        $this->actAs($userA);

        $response = $this->getJson("/api/v1/b2b/customer/orders/{$soB->hash_id}");

        $response->assertStatus(403);
    }

    public function test_sales_order_detail_succeeds_for_own_customer(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $so = SalesOrder::factory()->create(['customer_id' => $customer->id]);

        $this->actAs($user);

        $response = $this->getJson("/api/v1/b2b/customer/orders/{$so->hash_id}");

        $response->assertOk();
    }

    /* ─── Invoices ───────────────────────────────────────────────── */

    public function test_invoices_scoped_to_own_customer(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        Invoice::factory()->count(2)->create(['customer_id' => $customer->id]);

        $other = Customer::factory()->create();
        Invoice::factory()->create(['customer_id' => $other->id]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/invoices');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_invoice_detail_forbidden_for_other_customer(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $userA = $this->makePortalUser($customerA);

        $inv = Invoice::factory()->create(['customer_id' => $customerB->id]);

        $this->actAs($userA);

        $response = $this->getJson("/api/v1/b2b/customer/invoices/{$inv->hash_id}");

        $response->assertStatus(403);
    }

    /* ─── Complaints ─────────────────────────────────────────────── */

    public function test_complaints_scoped_to_own_customer(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $internalUser = User::factory()->create();

        CustomerComplaint::create([
            'complaint_number' => 'CC-T-' . substr(uniqid(), -5),
            'customer_id'      => $customer->id,
            'severity'         => 'low',
            'description'      => 'Test complaint A',
            'affected_quantity' => 5,
            'status'           => 'open',
            'received_date'    => now(),
            'created_by'       => $internalUser->id,
        ]);

        // Other customer's complaint — must NOT appear.
        $other = Customer::factory()->create();
        CustomerComplaint::create([
            'complaint_number' => 'CC-T-' . substr(uniqid(), -5),
            'customer_id'      => $other->id,
            'severity'         => 'high',
            'description'      => 'Other complaint',
            'affected_quantity' => 1,
            'status'           => 'open',
            'received_date'    => now(),
            'created_by'       => $internalUser->id,
        ]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/complaints');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_create_complaint_sets_own_customer_id(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        $this->actAs($user);

        $response = $this->postJson('/api/v1/b2b/customer/complaints', [
            'severity'          => 'critical',
            'description'       => 'Parts arrived damaged',
            'affected_quantity' => 10,
        ]);

        $response->assertStatus(201);

        $complaint = CustomerComplaint::where('customer_id', $customer->id)->first();
        $this->assertNotNull($complaint);
        $this->assertSame($customer->id, $complaint->customer_id);
        $this->assertSame('open', $complaint->status->value);
    }

    /* ─── Delivery Schedules ─────────────────────────────────────── */

    public function test_delivery_schedules_scoped_to_own_customer(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        DeliverySchedule::create([
            'customer_id' => $customer->id,
            'month'       => '2026-07',
            'status'      => 'submitted',
            'lines'       => [['product' => 'A', 'qty' => 100]],
        ]);

        // Other customer's schedule — must NOT appear.
        $other = Customer::factory()->create();
        DeliverySchedule::create([
            'customer_id' => $other->id,
            'month'       => '2026-07',
            'status'      => 'submitted',
            'lines'       => [['product' => 'B', 'qty' => 200]],
        ]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/delivery-schedules');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_store_delivery_schedule(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        $this->actAs($user);

        $response = $this->postJson('/api/v1/b2b/customer/delivery-schedules', [
            'month' => '2026-08',
            'lines' => [['product_name' => 'Relay Cover', 'quantity' => 500]],
        ]);

        $response->assertStatus(201);

        $schedule = DeliverySchedule::where('customer_id', $customer->id)->first();
        $this->assertNotNull($schedule);
        $this->assertSame('submitted', $schedule->status);
    }

    /* ─── Auth guard ─────────────────────────────────────────────── */

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/b2b/customer/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/b2b/customer/orders')->assertStatus(401);
        $this->getJson('/api/v1/b2b/customer/invoices')->assertStatus(401);
        $this->getJson('/api/v1/b2b/customer/complaints')->assertStatus(401);
    }
}
