<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Services\CustomerPortalService;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\PriceAgreementService;
use App\Modules\CRM\Services\PriceNegotiationService;
use App\Modules\CRM\Services\ProductService;
use App\Modules\CRM\Services\SalesOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerStandardPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        app(SettingsService::class)->set('company.vat_status', 'Non-VAT', 'company');
    }

    private function customer(): CustomerPortalUser
    {
        return CustomerPortalUser::create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Customer',
            'email' => uniqid().'@test.local', 'password' => bcrypt('password'), 'is_active' => true]);
    }

    private function agreement(CustomerPortalUser $user, Product $product, array $extra = []): PriceAgreement
    {
        return app(PriceAgreementService::class)->create(array_merge([
            'customer_id' => $user->customer_id, 'product_id' => $product->id, 'price' => '23.00',
            'effective_from' => today()->toDateString(), 'effective_to' => today()->addMonth()->toDateString(),
        ], $extra));
    }

    private function acceptReplacement(CustomerPortalUser $buyer, Product $product, array $terms): void
    {
        $service = app(PriceNegotiationService::class);
        $sales = User::factory()->create(['role_id' => Role::where('slug', 'sales_officer')->value('id')]);
        $finance = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $request = $service->offer(null, $sales, array_merge([
            'customer_id' => $buyer->customer_id, 'product_id' => $product->id, 'price' => '24.00', 'pricing_method' => 'flat',
            'effective_from' => today()->addDays(4)->toDateString(), 'effective_to' => today()->addMonths(2)->toDateString(), 'notes' => 'Dated replacement',
        ], $terms));
        $service->approve($request, $finance, ['notes' => 'Cost reviewed', 'version' => $request->fresh()->version, 'cost_signature' => $service->reviewCost($product)['signature']]);
        $service->respond($request, $buyer, 'accept', 1);
    }

    public function test_standard_base_prices_and_private_assignments_are_independent_of_agreements(): void
    {
        $user = $this->customer();
        $standard = Product::factory()->create(['base_selling_price' => '25.00', 'portal_visibility' => 'standard']);
        $private = Product::factory()->create(['base_selling_price' => '30.00', 'portal_visibility' => 'restricted']);
        $private->portalCustomers()->attach($user->customer_id);
        $hidden = Product::factory()->create(['base_selling_price' => '40.00', 'portal_visibility' => 'restricted']);
        $missing = Product::factory()->create(['portal_visibility' => 'standard']);
        $catalog = app(CustomerPortalService::class)->catalog($user->customer_id);
        $this->assertEqualsCanonicalizing([$standard->hash_id, $private->hash_id], array_column($catalog, 'id'));
        $this->actingAs($user, 'customer_portal');
        $date = today()->addDays(4)->toDateString();
        $this->postJson('/api/v1/b2b/customer/orders', ['items' => [['product_id' => $standard->hash_id, 'quantity' => '100', 'delivery_date' => $date]]], ['Idempotency-Key' => 'base-order'])
            ->assertCreated()->assertJsonPath('data.total_amount', '2500.00')->assertJsonPath('data.items.0.price_source', 'base');
        $this->postJson('/api/v1/b2b/customer/orders/quote', ['items' => [['product_id' => $hidden->hash_id, 'quantity' => '1', 'delivery_date' => $date]]])->assertStatus(422);
        $this->assertNotContains($missing->hash_id, array_column($catalog, 'id'));
    }

    public function test_server_quote_applies_delivery_date_volume_override_and_detects_changed_prices(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create(['standard_cost' => '10.00', 'minimum_selling_price' => '15.00', 'base_selling_price' => '25.00', 'portal_visibility' => 'standard']);
        $this->agreement($user, $product, ['pricing_method' => 'tiered',
            'tiers' => [['min_qty' => 100, 'unit_price' => '20.00']]]);
        $this->actingAs($user, 'customer_portal');
        $payload = ['items' => [['product_id' => $product->hash_id, 'quantity' => '100', 'delivery_date' => today()->addDays(4)->toDateString()]]];
        $quote = $this->postJson('/api/v1/b2b/customer/orders/quote', $payload)->assertOk()
            ->assertJsonPath('data.total_amount', '2000.00')->assertJsonPath('data.items.0.price_source', 'agreement')->json('data');
        $this->acceptReplacement($user, $product, ['pricing_method' => 'tiered', 'tiers' => [['min_qty' => 100, 'unit_price' => '21.00']]]);
        $payload['quote_signature'] = $quote['signature'];
        $this->postJson('/api/v1/b2b/customer/orders', $payload, ['Idempotency-Key' => 'stale-quote'])->assertStatus(422);
        $this->assertDatabaseCount('sales_orders', 0);
        $quote = $this->postJson('/api/v1/b2b/customer/orders/quote', $payload)->assertOk()->json('data');
        $payload['quote_signature'] = $quote['signature'];
        $this->postJson('/api/v1/b2b/customer/orders', $payload, ['Idempotency-Key' => 'fresh-quote'])->assertCreated()->assertJsonPath('data.total_amount', '2100.00');
    }

    public function test_expired_agreement_falls_back_to_base_without_changing_private_visibility(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create(['base_selling_price' => '25.00', 'portal_visibility' => 'restricted']);
        $product->portalCustomers()->attach($user->customer_id);
        $this->agreement($user, $product, ['effective_from' => today()->subMonth()->toDateString(), 'effective_to' => today()->subDay()->toDateString()]);
        $quote = app(PriceAgreementService::class)->quote($user->customer_id, $product->id, today(), '10');
        $this->assertSame('base', $quote['price_source']);
        $this->assertSame('25.00', $quote['unit_price']);
        $other = $this->customer();
        $this->assertSame([], app(CustomerPortalService::class)->catalog($other->customer_id));
    }

    public function test_configured_floor_blocks_unsafe_base_and_agreement_prices_including_tiers(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create(['standard_cost' => '18.00', 'base_selling_price' => '25.00', 'minimum_selling_price' => '22.00']);
        try {
            $this->agreement($user, $product, ['price' => '21.00']);
            $this->fail('Below-floor agreement must be blocked.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('price', $e->errors());
        }
        try {
            $this->agreement($user, $product, ['pricing_method' => 'tiered', 'tiers' => [['min_qty' => 100, 'unit_price' => '0.00']]]);
            $this->fail('Zero tier price must be blocked.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tiers.0.unit_price', $e->errors());
        }
        $this->expectException(ValidationException::class);
        app(ProductService::class)->update($product, ['base_selling_price' => '20.00']);
    }

    public function test_notes_only_save_keeps_original_prices_after_accepted_replacement(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create(['standard_cost' => '10.00', 'minimum_selling_price' => '15.00', 'base_selling_price' => '25.00', 'portal_visibility' => 'standard']);
        $agreement = $this->agreement($user, $product);
        $items = [['product_id' => $product->id, 'quantity' => '10', 'delivery_date' => today()->addDays(4)->toDateString()]];
        $order = app(SalesOrderService::class)->create(['customer_id' => $user->customer_id, 'date' => today()->toDateString(), 'items' => $items], User::factory()->create()->id);
        $lineId = $order->items->first()->id;
        $this->acceptReplacement($user, $product, []);
        $updated = app(SalesOrderService::class)->update($order, ['items' => $items, 'notes' => 'Note only']);
        $this->assertSame('230.00', $updated->total_amount);
        $this->assertSame($lineId, $updated->items->first()->id);
        $this->assertSame('agreement', $updated->items->first()->price_source);
        $this->assertSame($agreement->id, $updated->items->first()->price_agreement_id);
    }

    public function test_unsafe_applicable_agreement_cannot_fall_back_or_disclose_the_floor_to_customers(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create(['base_selling_price' => '25.00', 'portal_visibility' => 'standard']);
        $this->agreement($user, $product);
        app(ProductService::class)->update($product, ['minimum_selling_price' => '24.00']);
        $this->actingAs($user, 'customer_portal');
        $response = $this->postJson('/api/v1/b2b/customer/orders/quote', ['items' => [['product_id' => $product->hash_id,
            'quantity' => '10', 'delivery_date' => today()->addDays(4)->toDateString()]]])->assertStatus(422);
        $this->assertStringNotContainsString('24.00', $response->getContent());
        $this->assertStringNotContainsString('minimum_selling_price', $response->getContent());
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_unexpired_agreement_cannot_be_transferred_to_another_customer(): void
    {
        $user = $this->customer();
        $other = $this->customer();
        $product = Product::factory()->create(['base_selling_price' => '25.00']);
        $agreement = $this->agreement($user, $product);
        try {
            app(PriceAgreementService::class)->update($agreement, ['customer_id' => $other->customer_id]);
            $this->fail('Customer terms must not be silently transferred.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('locked', $error->getMessage());
        }
        $this->assertTrue($product->isAvailableToCustomer($user->customer_id));
        $this->assertFalse($product->isAvailableToCustomer($other->customer_id));
    }

    public function test_migration_preserves_orders_and_backfills_access_without_publishing_prices(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create();
        $this->agreement($user, $product);
        $order = app(SalesOrderService::class)->create(['customer_id' => $user->customer_id, 'date' => today()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => '10', 'delivery_date' => today()->addDays(4)->toDateString()]]], User::factory()->create()->id);
        $line = $order->items->first();
        $migration = require database_path('migrations/2026_10_04_000002_add_standard_customer_pricing.php');
        $migration->down();
        $migration->up();
        $this->assertSame('230.00', $order->fresh()->total_amount);
        $this->assertSame('23.00', $line->fresh()->unit_price);
        $this->assertNull($line->fresh()->price_source);
        $this->assertNull($product->fresh()->base_selling_price);
        $this->assertNull($product->fresh()->minimum_selling_price);
        $this->assertSame('restricted', $product->fresh()->portal_visibility);
        $this->assertTrue($product->isAvailableToCustomer($user->customer_id));
    }
}
