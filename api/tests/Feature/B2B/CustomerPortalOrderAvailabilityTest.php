<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Customer;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\PricingMethod;
use App\Modules\CRM\Models\CustomerProductWarrantyOption;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\Item;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPortalOrderAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private int $requestNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        app(\App\Common\Services\SettingsService::class)->set('company.vat_status', 'Non-VAT', 'company');
    }

    public function test_catalog_exposes_authoritative_stock_and_per_product_warranty_choices(): void
    {
        [$customer, $portal, $product] = $this->portalProduct();
        Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good', 'safety_stock' => 0]);
        $warranty = CustomerProductWarrantyOption::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'label' => '90 days',
            'warranty_duration_days' => 90,
            'warranty_report_window_days' => 30,
            'warranty_start_basis' => 'delivery',
            'warranty_covered_issues' => ['manufacturing_defect'],
            'warranty_remedies' => ['repair'],
            'position' => 1,
        ]);

        $this->actingAs($portal, 'customer_portal')->getJson('/api/v1/b2b/customer/catalog')
            ->assertOk()
            ->assertJsonPath('data.0.available_quantity', '0.000')
            ->assertJsonPath('data.0.availability_mode', 'stock_limited')
            ->assertJsonPath('data.0.warranty_options.0.id', $warranty->hash_id)
            ->assertJsonPath('data.0.warranty_options.0.warranty_duration_days', 90);
    }

    public function test_catalog_marks_products_without_a_finished_good_inventory_mapping_as_make_to_order(): void
    {
        [, $portal] = $this->portalProduct();

        $this->actingAs($portal, 'customer_portal')->getJson('/api/v1/b2b/customer/catalog')
            ->assertOk()
            ->assertJsonPath('data.0.available_quantity', null)
            ->assertJsonPath('data.0.availability_mode', 'make_to_order');
    }

    public function test_restricted_customer_catalog_contains_only_products_assigned_to_that_customer(): void
    {
        [$customer, $portal, $allowed] = $this->portalProduct(['portal_catalog_mode' => 'restricted']);
        $this->assertSame('restricted', $customer->fresh()->portal_catalog_mode);
        Product::factory()->create(['base_selling_price' => '150.00', 'portal_visibility' => 'restricted']);

        $response = $this->actingAs($portal, 'customer_portal')->getJson('/api/v1/b2b/customer/catalog')->assertOk();
        $this->assertSame([$allowed->hash_id], array_column($response->json('data'), 'id'));
    }

    public function test_stock_limited_order_cannot_be_placed_when_eligible_stock_is_zero(): void
    {
        [$customer, $portal, $product] = $this->portalProduct();
        Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good']);
        // A mapped item without a QC-released production receipt has no eligible stock.
        $this->actingAs($portal, 'customer_portal');
        $this->postOrder([[
            'product_id' => $product->hash_id,
            'quantity' => 1,
            'delivery_date' => now()->addDays(7)->toDateString(),
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
    }

    public function test_make_to_order_order_snapshots_selected_customer_warranty_terms(): void
    {
        [$customer, $portal, $product] = $this->portalProduct();
        $warranty = CustomerProductWarrantyOption::query()->create([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'label' => '90 days',
            'warranty_duration_days' => 90, 'warranty_report_window_days' => 30,
            'warranty_start_basis' => 'delivery', 'warranty_covered_issues' => ['manufacturing_defect'],
            'warranty_remedies' => ['repair'], 'position' => 1,
        ]);

        $this->actingAs($portal, 'customer_portal');
        $this->postOrder([[
            'product_id' => $product->hash_id,
            'quantity' => 1,
            'delivery_date' => now()->addDays(7)->toDateString(),
            'warranty_option_id' => $warranty->hash_id,
        ]])->assertCreated();

        $line = SalesOrder::query()->where('customer_id', $customer->id)->firstOrFail()->items()->firstOrFail();
        $this->assertSame('90 days', $line->warranty_terms_snapshot['warranty_option_label']);
        $this->assertSame(90, $line->warranty_terms_snapshot['warranty_duration_days']);
    }

    public function test_customer_credit_limit_does_not_block_order_confirmation(): void
    {
        [$customer, $portal, $product] = $this->portalProduct(['credit_limit' => '1.00']);
        $order = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'draft',
            'total_amount' => '1000.00',
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'quantity' => '1.00', 'unit_price' => '1000.00',
            'total' => '1000.00', 'quantity_delivered' => '0.00', 'delivery_date' => now()->addDays(7),
        ]);
        $admin = \App\Modules\Auth\Models\User::factory()->create([
            'role_id' => \App\Modules\Auth\Models\Role::where('slug', 'system_admin')->value('id'),
        ]);
        $this->actingAs($admin)->postJson('/api/v1/crm/sales-orders/'.$order->hash_id.'/confirm')->assertOk();
    }

    /** @return array{Customer, CustomerPortalUser, Product} */
    private function portalProduct(array $customerAttributes = []): array
    {
        $customer = Customer::factory()->create($customerAttributes);
        $portal = CustomerPortalUser::query()->create([
            'customer_id' => $customer->id, 'name' => 'Portal Customer',
            'email' => 'availability-'.uniqid().'@example.test', 'password' => 'Password1!',
            'is_active' => true, 'must_change_password' => false,
        ]);
        $product = Product::factory()->create();
        $product->portalCustomers()->attach($customer->id);
        PriceAgreement::query()->create([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'price' => '100.00',
            'effective_from' => now()->subDay()->toDateString(), 'effective_to' => now()->addMonth()->toDateString(),
            'pricing_method' => PricingMethod::Flat->value,
        ]);

        return [$customer, $portal, $product];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function postOrder(array $items): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/b2b/customer/orders', [
            'items' => $items,
            'payment_method' => 'cash',
        ], ['Idempotency-Key' => 'availability-test-'.(++$this->requestNumber)]);
    }
}
