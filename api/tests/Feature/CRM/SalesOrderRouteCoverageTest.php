<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\PricingMethod;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesOrderRouteCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    public function test_sales_order_crud_round_trip_preserves_incoterm_and_supports_restore(): void
    {
        [$customer, $product] = $this->references();
        $this->agreement($customer, $product);
        $actor = $this->actor([
            'crm.sales_orders.create',
            'crm.sales_orders.update',
            'crm.sales_orders.delete',
        ]);
        $payload = $this->orderPayload($customer, $product, 'FOB');

        $created = $this->actingAs($actor)
            ->postJson('/api/v1/crm/sales-orders', $payload);

        $created->assertCreated()
            ->assertJsonPath('data.incoterm', 'FOB');
        $so = SalesOrder::query()
            ->where('so_number', $created->json('data.so_number'))
            ->firstOrFail();

        $this->actingAs($actor)
            ->putJson("/api/v1/crm/sales-orders/{$so->hash_id}", [
                ...$payload,
                'incoterm' => 'CIF',
            ])
            ->assertOk()
            ->assertJsonPath('data.incoterm', 'CIF');

        $this->actingAs($actor)
            ->deleteJson("/api/v1/crm/sales-orders/{$so->hash_id}")
            ->assertNoContent();
        $this->assertNotNull(SalesOrder::withTrashed()->findOrFail($so->id)->deleted_at);

        $this->actingAs($actor)
            ->patchJson("/api/v1/crm/sales-orders/{$so->hash_id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $so->hash_id);
        $this->assertNull(SalesOrder::withTrashed()->findOrFail($so->id)->deleted_at);
    }

    public function test_update_route_rejects_delivery_date_before_order_date(): void
    {
        [$customer, $product] = $this->references();
        $so = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'date' => today()->toDateString(),
            'status' => SalesOrderStatus::Draft->value,
        ]);
        SalesOrderItem::query()->create([
            'sales_order_id' => $so->id,
            'product_id' => $product->id,
            'quantity' => '1.00',
            'unit_price' => '10.00',
            'total' => '10.00',
            'quantity_delivered' => '0.00',
            'delivery_date' => today()->addDay()->toDateString(),
        ]);

        $response = $this->actingAs($this->actor('crm.sales_orders.update'))
            ->putJson("/api/v1/crm/sales-orders/{$so->hash_id}", [
                'customer_id' => $customer->hash_id,
                'date' => today()->toDateString(),
                'items' => [[
                    'product_id' => $product->hash_id,
                    'quantity' => '1.00',
                    'delivery_date' => today()->subDay()->toDateString(),
                ]],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.delivery_date']);
    }

    public function test_confirmation_rechecks_customer_activity_after_draft_creation(): void
    {
        [$customer, $product] = $this->references();
        $so = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'status' => SalesOrderStatus::Draft->value,
        ]);
        SalesOrderItem::query()->create([
            'sales_order_id' => $so->id,
            'product_id' => $product->id,
            'quantity' => '1.00',
            'unit_price' => '10.00',
            'total' => '10.00',
            'quantity_delivered' => '0.00',
            'delivery_date' => today()->addDay()->toDateString(),
        ]);
        $customer->update(['is_active' => false]);

        try {
            app(SalesOrderService::class)->confirm($so->fresh());
            $this->fail('Confirmation must reject a customer deactivated after draft creation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_id', $e->errors());
        }

        $this->assertSame(SalesOrderStatus::Draft, $so->fresh()->status);
    }

    public function test_confirmation_rechecks_product_activity_after_draft_creation(): void
    {
        [$customer, $product] = $this->references();
        $so = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'status' => SalesOrderStatus::Draft->value,
        ]);
        SalesOrderItem::query()->create([
            'sales_order_id' => $so->id,
            'product_id' => $product->id,
            'quantity' => '1.00',
            'unit_price' => '10.00',
            'total' => '10.00',
            'quantity_delivered' => '0.00',
            'delivery_date' => today()->addDay()->toDateString(),
        ]);
        $product->update(['is_active' => false]);

        try {
            app(SalesOrderService::class)->confirm($so->fresh());
            $this->fail('Confirmation must reject a product deactivated after draft creation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.product_id', $e->errors());
        }

        $this->assertSame(SalesOrderStatus::Draft, $so->fresh()->status);
    }

    public function test_generic_transition_endpoint_is_not_available(): void
    {
        $so = SalesOrder::factory()->create();
        $response = $this->actingAs($this->actor('crm.sales_orders.view'))
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/transition", [
                'status' => SalesOrderStatus::Delivered->value,
            ]);

        $this->assertContains($response->status(), [404, 405]);
    }

    public function test_restore_route_requires_delete_permission(): void
    {
        $so = SalesOrder::factory()->create(['status' => SalesOrderStatus::Draft->value]);
        $so->delete();

        $this->actingAs($this->actor('crm.sales_orders.view'))
            ->patchJson("/api/v1/crm/sales-orders/{$so->hash_id}/restore")
            ->assertForbidden();
    }

    public function test_cancel_route_rejects_an_order_with_active_delivery(): void
    {
        $so = SalesOrder::factory()->create([
            'status' => SalesOrderStatus::Confirmed->value,
        ]);
        Delivery::query()->create([
            'delivery_number' => 'M033-DEL-'.uniqid(),
            'sales_order_id' => $so->id,
            'status' => 'scheduled',
            'scheduled_date' => today(),
            'created_by' => User::factory()->create()->id,
        ]);

        $this->actingAs($this->actor('crm.sales_orders.cancel'))
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/cancel", ['reason' => 'Test'])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'This sales order cannot be cancelled while a delivery is active.']);

        $this->assertSame(SalesOrderStatus::Confirmed, $so->fresh()->status);
    }

    public function test_named_write_routes_require_their_permissions(): void
    {
        [$customer, $product] = $this->references();
        $so = SalesOrder::factory()->create(['status' => SalesOrderStatus::Draft->value]);
        $actor = $this->actor('crm.sales_orders.view');
        $payload = $this->orderPayload($customer, $product, 'FOB');

        $this->actingAs($actor)
            ->postJson('/api/v1/crm/sales-orders', $payload)
            ->assertForbidden();
        $this->actingAs($actor)
            ->putJson("/api/v1/crm/sales-orders/{$so->hash_id}", $payload)
            ->assertForbidden();
        $this->actingAs($actor)
            ->deleteJson("/api/v1/crm/sales-orders/{$so->hash_id}")
            ->assertForbidden();
        $this->actingAs($actor)
            ->patchJson("/api/v1/crm/sales-orders/{$so->hash_id}/restore")
            ->assertForbidden();
        $this->actingAs($actor)
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm")
            ->assertForbidden();
        $this->actingAs($actor)
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/cancel")
            ->assertForbidden();
    }

    public function test_customer_portal_order_page_two_preserves_pagination_metadata(): void
    {
        $customer = Customer::factory()->create();
        $portalUser = CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Portal Test User',
            'email' => 'portal-'.uniqid().'@test.invalid',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
        SalesOrder::factory()->count(26)->create(['customer_id' => $customer->id]);

        Sanctum::actingAs($portalUser, ['*'], 'customer_portal');
        $response = $this->getJson('/api/v1/b2b/customer/orders?page=2');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 26);
        $this->assertCount(1, $response->json('data'));
    }

    /** @return array{0: Customer, 1: Product} */
    private function references(): array
    {
        return [Customer::factory()->create(), Product::factory()->create()];
    }

    private function agreement(Customer $customer, Product $product): PriceAgreement
    {
        return PriceAgreement::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => '10.00',
            'pricing_method' => PricingMethod::Flat,
            'effective_from' => today()->subDay()->toDateString(),
            'effective_to' => today()->addMonth()->toDateString(),
        ]);
    }

    /** @return array<string, mixed> */
    private function orderPayload(Customer $customer, Product $product, string $incoterm): array
    {
        return [
            'customer_id' => $customer->hash_id,
            'date' => today()->toDateString(),
            'payment_terms_days' => 30,
            'delivery_terms' => 'Standard delivery',
            'incoterm' => $incoterm,
            'items' => [[
                'product_id' => $product->hash_id,
                'quantity' => '2.00',
                'delivery_date' => today()->addDay()->toDateString(),
            ]],
        ];
    }

    /** @param string|list<string> $permissions */
    private function actor(string|array $permissions): User
    {
        $permissions = (array) $permissions;
        $role = Role::query()->create([
            'name' => 'M033 Test '.uniqid(),
            'slug' => 'm033_test_'.uniqid(),
        ]);
        foreach ($permissions as $slug) {
            $permission = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst(str_replace('.', ' ', $slug)), 'module' => 'crm'],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
