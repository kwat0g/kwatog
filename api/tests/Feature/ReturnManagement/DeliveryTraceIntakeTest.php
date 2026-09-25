<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryTraceIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    public function test_portal_tracking_is_owned_idempotent_and_cannot_authorize_settlement(): void
    {
        [$delivery, $portal] = $this->source();
        $url = '/api/v1/b2b/customer/problems/not-arrived/'.$delivery->hash_id;
        $payload = ['request_key' => (string) Str::uuid(), 'message' => 'Still waiting at the receiving dock.'];
        $case = $this->actingAs($portal, 'customer_portal')->postJson($url, $payload)->assertCreated()->json('data');
        $this->assertSame('delivery_trace', $case['intake_kind']);
        $this->assertSame([], $case['lines']);
        $this->assertFalse($case['can_resolve_trace']);
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.id', $case['id']);
        $this->postJson('/api/v1/b2b/customer/problems/'.$case['id'].'/actions', ['action' => 'agree', 'resolution' => 'credit'])->assertForbidden();
        $this->postJson('/api/v1/b2b/customer/problems/'.$case['id'].'/actions', ['action' => 'resolve_trace', 'message' => 'Not arrived yet.'])->assertUnprocessable();
        $other = $this->portal(Customer::factory()->create());
        $this->actingAs($other, 'customer_portal')->postJson($url, ['request_key' => (string) Str::uuid()])->assertNotFound();
        $this->getJson('/api/v1/b2b/customer/problems/'.$case['id'])->assertForbidden();
        $this->assertSame(1, ReturnCase::query()->count());
    }

    public function test_tracking_close_on_arrival_releases_hold_without_stock_or_financial_documents(): void
    {
        [$delivery, $portal] = $this->source();
        $case = $this->actingAs($portal, 'customer_portal')->postJson('/api/v1/b2b/customer/problems/not-arrived/'.$delivery->hash_id,
            ['request_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
        $this->postJson('/api/v1/b2b/customer/problems/'.$case['id'].'/actions', ['action' => 'resolve_trace', 'message' => 'It arrived at the dock.'])->assertOk()
            ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.return_request', null)->assertJsonPath('data.credit_note', null);
        $this->assertNull($delivery->fresh()->blockingReturnCase);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_future_scheduled_and_confirmed_shipments_do_not_accept_tracking_intake(): void
    {
        [$delivery, $portal] = $this->source();
        $url = '/api/v1/b2b/customer/problems/not-arrived/'.$delivery->hash_id;
        $delivery->forceFill(['status' => 'scheduled', 'scheduled_date' => today()->addDays(2)])->save();
        $this->actingAs($portal, 'customer_portal')->postJson($url, ['request_key' => (string) Str::uuid()])->assertUnprocessable();
        $delivery->forceFill(['status' => 'confirmed'])->save();
        $this->postJson($url, ['request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('return_cases', 0);
    }

    private function source(): array
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $order = SalesOrder::factory()->create(['customer_id' => $customer->id, 'created_by' => $user->id]);
        // An existing shipment is enough for intake. This test must not invent
        // a stock issue to test a customer communication-only route.
        $delivery = Delivery::create(['delivery_number' => 'DEL-TRC-'.Str::random(8), 'sales_order_id' => $order->id,
            'status' => 'in_transit', 'scheduled_date' => today(), 'created_by' => $user->id]);
        return [$delivery, $this->portal($customer)];
    }

    private function portal(Customer $customer): CustomerPortalUser
    {
        return CustomerPortalUser::create(['customer_id' => $customer->id, 'name' => 'Receiving contact',
            'email' => Str::uuid().'@test.local', 'password' => 'Password1!', 'is_active' => true, 'password_changed_at' => now()]);
    }
}
