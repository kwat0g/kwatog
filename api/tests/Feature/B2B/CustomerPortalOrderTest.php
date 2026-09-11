<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Common\Services\SystemUserResolver;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\PricingMethod;
use App\Modules\CRM\Enums\SalesOrderSubmissionSource;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer self-service ordering — the customer places a Sales Order through
 * the B2B portal. Pins catalog scoping, price-resolution, audit attribution,
 * internal review notification, and cross-customer isolation on the HTTP path.
 */
class CustomerPortalOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        // Tests that care about VAT enable it explicitly; the default here is
        // non-VAT so total == subtotal regardless of the host env.
        app(SettingsService::class)->set('company.vat_status', 'Non-VAT', 'company');
    }

    /* ─── Helpers ────────────────────────────────────────────────── */

    private function makePortalUser(?Customer $customer = null): CustomerPortalUser
    {
        $customer ??= Customer::factory()->create();

        return CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'CustUser-'.substr(uniqid(), -5),
            'email' => 'cu-'.uniqid().'@t.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }

    private function actAs(CustomerPortalUser $user): self
    {
        $this->actingAs($user, 'customer_portal');

        return $this;
    }

    private function agreement(Customer $customer, Product $product, string $price = '100.00'): PriceAgreement
    {
        return PriceAgreement::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => $price,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => now()->addMonth()->toDateString(),
            'pricing_method' => PricingMethod::Flat->value,
        ]);
    }

    /* ─── Catalog ────────────────────────────────────────────────── */

    public function test_catalog_returns_only_products_with_active_agreement_for_own_customer(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        $inCatalog = Product::factory()->create();
        $this->agreement($customer, $inCatalog, '250.00');

        $otherCustomer = Customer::factory()->create();
        $otherProduct = Product::factory()->create();
        $this->agreement($otherCustomer, $otherProduct, '500.00');

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/catalog');

        $response->assertOk();
        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame($inCatalog->hash_id, $items[0]['id']);
        $this->assertSame('250.00', $items[0]['unit_price']);
        $this->assertSame('flat', $items[0]['pricing_method']);
    }

    public function test_catalog_excludes_archived_products_and_expired_agreements(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        $archived = Product::factory()->create();
        $this->agreement($customer, $archived);
        $archived->delete();

        $expired = Product::factory()->create();
        PriceAgreement::create([
            'customer_id' => $customer->id,
            'product_id' => $expired->id,
            'price' => '75.00',
            'effective_from' => now()->subMonths(2)->toDateString(),
            'effective_to' => now()->subDay()->toDateString(),
            'pricing_method' => PricingMethod::Flat->value,
        ]);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/catalog');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_catalog_search_filters_by_part_number(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        $target = Product::factory()->create(['part_number' => 'XX-NEEDLE-01']);
        $this->agreement($customer, $target);
        $other = Product::factory()->create(['part_number' => 'ZZ-OTHER-02']);
        $this->agreement($customer, $other);

        $this->actAs($user);

        $response = $this->getJson('/api/v1/b2b/customer/catalog?search=NEEDLE');

        $response->assertOk();
        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame($target->hash_id, $items[0]['id']);
    }

    /* ─── Place Order ────────────────────────────────────────────── */

    public function test_place_order_creates_draft_so_for_own_customer(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product, '120.50');

        $this->actAs($user);

        $response = $this->postJson('/api/v1/b2b/customer/orders', [
            'items' => [
                ['product_id' => $product->hash_id, 'quantity' => 4, 'delivery_date' => now()->addDays(7)->toDateString()],
            ],
            'notes' => 'Rush order via portal',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.submission_source', SalesOrderSubmissionSource::CustomerPortal->value);

        $so = SalesOrder::where('customer_id', $customer->id)->first();
        $this->assertNotNull($so);
        $this->assertSame('customer_portal', $so->submission_source->value);
        $this->assertSame('draft', $so->status->value);
        $this->assertSame('482.00', (string) $so->subtotal);   // 4 × 120.50
        $this->assertSame('482.00', (string) $so->total_amount);

        $line = $so->items()->first();
        $this->assertNotNull($line);
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame('4.00', (string) $line->quantity);
        $this->assertSame('120.50', (string) $line->unit_price);
        $this->assertSame('482.00', (string) $line->total);
    }

    public function test_place_order_uses_customer_default_payment_terms_and_applies_vat(): void
    {
        app(SettingsService::class)->set('company.vat_status', 'VAT Registered', 'company');
        app(SettingsService::class)->set('tax.ph.vat_rate', 0.12, 'tax');

        $customer = Customer::factory()->create(['payment_terms_days' => 45]);
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product, '1000.00');

        $this->actAs($user)
            ->postJson('/api/v1/b2b/customer/orders', [
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 2, 'delivery_date' => now()->addDays(3)->toDateString()],
                ],
            ])
            ->assertStatus(201);

        $so = SalesOrder::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('2000.00', (string) $so->subtotal);
        $this->assertSame('240.00', (string) $so->vat_amount);
        $this->assertSame('2240.00', (string) $so->total_amount);
        $this->assertSame(45, $so->payment_terms_days);
    }

    public function test_place_order_rejects_product_without_active_agreement(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();

        $this->actAs($user)
            ->postJson('/api/v1/b2b/customer/orders', [
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => now()->addDays(3)->toDateString()],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_place_order_rejects_another_customers_agreed_product(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($otherCustomer, $product, '99.00');

        $this->actAs($user)
            ->postJson('/api/v1/b2b/customer/orders', [
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => now()->addDays(3)->toDateString()],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_place_order_rejects_delivery_date_before_order_date(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);

        $this->actAs($user)
            ->postJson('/api/v1/b2b/customer/orders', [
                'date' => now()->toDateString(),
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => now()->subDay()->toDateString()],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_place_order_rejects_invalid_product_hash(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);

        $this->actAs($user)
            ->postJson('/api/v1/b2b/customer/orders', [
                'items' => [
                    ['product_id' => 'not-a-real-hash', 'quantity' => 1, 'delivery_date' => now()->addDays(3)->toDateString()],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_place_order_attributes_to_system_user_and_writes_external_audit(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);

        $systemUser = app(SystemUserResolver::class)->user();

        $this->actAs($user)
            ->postJson('/api/v1/b2b/customer/orders', [
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => now()->addDays(3)->toDateString()],
                ],
            ])
            ->assertStatus(201);

        $so = SalesOrder::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($systemUser->id, $so->created_by);

        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'customer_portal',
            'action' => 'customer.order.placed',
            'model_type' => SalesOrder::class,
            'model_id' => $so->id,
        ]);
        $log = AuditLog::where('action', 'customer.order.placed')->where('model_id', $so->id)->firstOrFail();
        $this->assertSame($user->hash_id, $log->new_values['portal_user_id']);
    }

    public function test_place_order_notifies_sales_staff_who_can_confirm(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->makePortalUser($customer);
        $product = Product::factory()->create();
        $this->agreement($customer, $product);

        $role = Role::query()->where('slug', 'sales_officer')->firstOrFail();
        $sales = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->actAs($user)
            ->postJson('/api/v1/b2b/customer/orders', [
                'items' => [
                    ['product_id' => $product->hash_id, 'quantity' => 1, 'delivery_date' => now()->addDays(3)->toDateString()],
                ],
            ])
            ->assertStatus(201);

        $so = SalesOrder::where('customer_id', $customer->id)->firstOrFail();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $sales->id,
            'type' => 'portal.order_awaiting_review',
        ]);

        $notification = \Illuminate\Support\Facades\DB::table('notifications')
            ->where('notifiable_id', $sales->id)
            ->where('type', 'portal.order_awaiting_review')
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString($so->so_number, $notification->data);
    }
}
