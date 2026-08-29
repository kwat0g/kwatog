<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\PricingMethod;
use App\Modules\CRM\Exceptions\NoPriceAgreementException;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\PriceAgreementService;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\Inventory\Models\Uom;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerProductPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        Uom::create(['code' => 'PCS', 'name' => 'Pieces']);
    }

    public function test_archived_product_can_be_restored_through_the_hash_route(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $response = $this->actingAs($this->actor('crm.products.manage'))
            ->patchJson("/api/v1/crm/products/{$product->hash_id}/restore");

        $response->assertOk();
        $this->assertFalse(Product::withTrashed()->findOrFail($product->id)->trashed());
    }

    public function test_archived_price_agreement_can_be_restored_and_overlap_is_rejected(): void
    {
        [$customer, $product] = $this->references();
        $active = $this->agreement($customer, $product);
        $archived = $this->agreement($customer, $product, price: '110.00');
        $archived->delete();

        $response = $this->actingAs($this->actor('crm.price_agreements.manage'))
            ->patchJson("/api/v1/crm/price-agreements/{$archived->hash_id}/restore");

        $response->assertUnprocessable();
        $response->assertJsonFragment(['message' => 'A price agreement already exists for this customer/product in the selected date range.']);
        $this->assertNotNull(PriceAgreement::withTrashed()->findOrFail($archived->id)->deleted_at);

        $active->delete();
        $response = $this->actingAs($this->actor('crm.price_agreements.manage'))
            ->patchJson("/api/v1/crm/price-agreements/{$archived->hash_id}/restore");

        $response->assertOk();
        $this->assertNull(PriceAgreement::withTrashed()->findOrFail($archived->id)->deleted_at);
    }

    public function test_price_agreement_api_crud_requires_manage_permission_and_exposes_tiers(): void
    {
        [$customer, $product] = $this->references();
        $payload = [
            'customer_id' => $customer->hash_id,
            'product_id' => $product->hash_id,
            'price' => '99.99',
            'effective_from' => now()->toDateString(),
            'effective_to' => now()->addMonth()->toDateString(),
            'pricing_method' => PricingMethod::Tiered->value,
            'tiers' => [
                ['min_qty' => 1, 'unit_price' => '12.34'],
                ['min_qty' => 100, 'unit_price' => '10.00'],
            ],
        ];

        $this->actingAs($this->actor('crm.price_agreements.view'))
            ->postJson('/api/v1/crm/price-agreements', $payload)
            ->assertForbidden();

        $response = $this->actingAs($this->actor('crm.price_agreements.manage'))
            ->postJson('/api/v1/crm/price-agreements', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.pricing_method', PricingMethod::Tiered->value)
            ->assertJsonPath('data.tiers.0.unit_price', '12.34');

        $id = $response->json('data.id');
        $this->actingAs($this->actor('crm.price_agreements.manage'))
            ->putJson("/api/v1/crm/price-agreements/{$id}", [...$payload, 'price' => '98.99'])
            ->assertOk()
            ->assertJsonPath('data.price', '98.99');

        $this->actingAs($this->actor('crm.price_agreements.manage'))
            ->deleteJson("/api/v1/crm/price-agreements/{$id}")
            ->assertNoContent();
    }

    public function test_inactive_or_archived_references_cannot_create_an_agreement(): void
    {
        [$customer, $product] = $this->references();
        $customer->update(['is_active' => false]);
        $product->delete();

        $response = $this->actingAs($this->actor('crm.price_agreements.manage'))
            ->postJson('/api/v1/crm/price-agreements', [
                'customer_id' => $customer->hash_id,
                'product_id' => $product->hash_id,
                'price' => '10.00',
                'effective_from' => now()->toDateString(),
                'effective_to' => now()->addMonth()->toDateString(),
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['customer_id', 'product_id']);
    }

    public function test_sales_order_api_rejects_an_inactive_customer_before_pricing_resolution(): void
    {
        [$customer, $product] = $this->references();
        $customer->update(['is_active' => false]);

        $response = $this->actingAs($this->actor('crm.sales_orders.create'))
            ->postJson('/api/v1/crm/sales-orders', [
                'customer_id' => $customer->hash_id,
                'date' => now()->toDateString(),
                'items' => [[
                    'product_id' => $product->hash_id,
                    'quantity' => '1',
                    'delivery_date' => now()->addDay()->toDateString(),
                ]],
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['customer_id']);
    }

    public function test_product_archive_is_blocked_until_pricing_and_bom_dependencies_are_retired(): void
    {
        [$customer, $product] = $this->references();
        $agreement = $this->agreement($customer, $product);

        $response = $this->actingAs($this->actor('crm.products.manage'))
            ->deleteJson("/api/v1/crm/products/{$product->hash_id}");

        $response->assertUnprocessable();
        $response->assertJsonFragment([
            'message' => 'Cannot archive this product while it has price agreements. Archive or retire the dependent records first.',
        ]);

        $agreement->delete();
        DB::table('bill_of_materials')->insert([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->actor('crm.products.manage'))
            ->deleteJson("/api/v1/crm/products/{$product->hash_id}");

        $response->assertUnprocessable();
        $response->assertJsonFragment([
            'message' => 'Cannot archive this product while it has an active BOM. Archive or retire the dependent records first.',
        ]);

        DB::table('bill_of_materials')->where('product_id', $product->id)->delete();
        $response = $this->actingAs($this->actor('crm.products.manage'))
            ->deleteJson("/api/v1/crm/products/{$product->hash_id}");

        $response->assertNoContent();
        $this->assertTrue(Product::withTrashed()->findOrFail($product->id)->trashed());
    }

    public function test_product_uom_is_normalized_and_must_exist_in_the_catalog(): void
    {
        $response = $this->actingAs($this->actor('crm.products.manage'))
            ->postJson('/api/v1/crm/products', [
                'part_number' => 'UOM-001',
                'name' => 'UOM Product',
                'unit_of_measure' => 'pcs',
                'standard_cost' => '12.34',
                'is_active' => true,
            ]);

        $response->assertCreated()->assertJsonPath('data.unit_of_measure', 'PCS');

        $response = $this->actingAs($this->actor('crm.products.manage'))
            ->postJson('/api/v1/crm/products', [
                'part_number' => 'UOM-002',
                'name' => 'Unknown UOM Product',
                'unit_of_measure' => 'EA',
                'standard_cost' => '12.34',
                'is_active' => true,
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['unit_of_measure']);
    }

    public function test_tiered_price_resolution_returns_exact_decimal_strings(): void
    {
        [$customer, $product] = $this->references();
        $agreement = app(PriceAgreementService::class)->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => '99.99',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => now()->addMonth()->toDateString(),
            'pricing_method' => PricingMethod::Tiered->value,
            'tiers' => [
                ['min_qty' => 1, 'unit_price' => '12.34'],
                ['min_qty' => 100, 'unit_price' => '10.00'],
            ],
        ]);

        $service = app(PriceAgreementService::class);
        $this->assertSame('12.34', $service->resolveUnitPrice($agreement, 1));
        $this->assertSame('10.00', $service->resolveUnitPrice($agreement, 100));
    }

    public function test_tiered_agreements_require_ascending_centavo_tiers(): void
    {
        [$customer, $product] = $this->references();

        $this->expectException(ValidationException::class);
        app(PriceAgreementService::class)->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => '99.99',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => now()->addMonth()->toDateString(),
            'pricing_method' => PricingMethod::Tiered->value,
            'tiers' => [
                ['min_qty' => 100, 'unit_price' => '10.001'],
                ['min_qty' => 10, 'unit_price' => '12.00'],
            ],
        ]);
    }

    /**
     * The inverted-window backstop in assertNoOverlap() must compare dates, not
     * raw strings. A partial update that sends only `effective_from` skips the
     * FormRequest's `after_or_equal` comparison, so the service guard is the
     * only thing standing between the caller and an impossible window — and a
     * non-ISO date string sorts wrong against an ISO one ('1' < '2'), which let
     * `12/01/2026` past a guard that correctly refused `2026-12-01`.
     *
     * An impossible window can never satisfy resolve(), so persisting one
     * silently removes every price for that customer/product.
     */
    public function test_inverted_window_is_refused_whatever_date_format_the_caller_uses(): void
    {
        [$customer, $product] = $this->references();

        foreach (['2026-12-01', '12/01/2026', '01-Dec-2026', 'December 1, 2026'] as $format) {
            $agreement = app(PriceAgreementService::class)->create([
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'price' => '10.00',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-03-31',
                'pricing_method' => PricingMethod::Flat->value,
            ]);

            $this->actingAs($this->actor('crm.price_agreements.manage'))
                ->putJson("/api/v1/crm/price-agreements/{$agreement->hash_id}", [
                    'effective_from' => $format,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['effective_to']);

            $stored = PriceAgreement::findOrFail($agreement->id);
            $this->assertTrue(
                $stored->effective_from->lessThanOrEqualTo($stored->effective_to),
                "Format {$format} persisted an inverted window: "
                    . $stored->effective_from->toDateString() . ' .. ' . $stored->effective_to->toDateString(),
            );

            $agreement->forceDelete();
        }
    }

    public function test_agreement_search_matches_product_and_customer_names(): void
    {
        [$customer, $product] = $this->references(customerName: 'Searchable Customer', productName: 'Searchable Product');
        $this->agreement($customer, $product);

        $byProduct = app(PriceAgreementService::class)->list(['search' => 'Searchable Product']);
        $this->assertCount(1, $byProduct->items());

        $byCustomer = app(PriceAgreementService::class)->list(['search' => 'Searchable Customer']);
        $this->assertCount(1, $byCustomer->items());
    }

    public function test_resolution_rejects_an_agreement_when_a_reference_is_deactivated(): void
    {
        [$customer, $product] = $this->references();
        $agreement = $this->agreement($customer, $product);
        $product->update(['is_active' => false]);

        $this->expectException(NoPriceAgreementException::class);
        app(PriceAgreementService::class)->resolve($customer->id, $product->id, now());
    }

    public function test_sales_order_totals_preserve_decimal_pricing_and_vat(): void
    {
        [$customer, $product] = $this->references();
        app(SettingsService::class)->set('company.vat_status', 'VAT Registered', 'company');
        app(SettingsService::class)->set('tax.ph.vat_rate', '0.075', 'tax');

        PriceAgreement::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => '10.10',
            'pricing_method' => PricingMethod::Flat,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => now()->addMonth()->toDateString(),
        ]);

        $so = app(SalesOrderService::class)->create([
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id,
                'quantity' => '3',
                'delivery_date' => now()->addWeek()->toDateString(),
            ]],
        ], $this->actor('crm.sales_orders.create')->id);

        $this->assertSame('30.30', (string) $so->subtotal);
        $this->assertSame('2.27', (string) $so->vat_amount);
        $this->assertSame('32.57', (string) $so->total_amount);
        $this->assertSame('10.10', (string) $so->items->first()->unit_price);
        $this->assertSame('30.30', (string) $so->items->first()->total);
    }

    /** @return array{0: Customer, 1: Product} */
    private function references(?string $customerName = null, ?string $productName = null): array
    {
        return [
            Customer::factory()->create(['name' => $customerName ?? 'Customer ' . uniqid()]),
            Product::factory()->create(['name' => $productName ?? 'Product ' . uniqid()]),
        ];
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

    private function actor(string $permissionSlug): User
    {
        $role = Role::create([
            'name' => 'M032 Test ' . uniqid(),
            'slug' => 'm032_test_' . uniqid(),
        ]);
        $permission = Permission::firstOrCreate(
            ['slug' => $permissionSlug],
            ['name' => ucfirst(str_replace('.', ' ', $permissionSlug)), 'module' => 'crm'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        return User::factory()->create(['role_id' => $role->id]);
    }
}
