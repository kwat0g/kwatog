<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Services\CustomerService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerContactLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    private function salesUser(): User
    {
        return User::factory()->withRole('sales_officer')->create(['is_active' => true]);
    }

    private function customerPayload(array $overrides = []): array
    {
        $product = Product::factory()->create();

        return array_merge([
            'name' => 'Honda Demo Corporation',
            'contact_person' => 'Mika Sato',
            'contact_person_role' => 'Sales Officer',
            'email' => 'mika@example.test',
            'address' => 'Honda Office, Santa Rosa, Laguna',
            'delivery_address' => 'Honda Plant, Santa Rosa, Laguna',
            'delivery_latitude' => '14.2860000',
            'delivery_longitude' => '120.9345000',
            'payment_terms_days' => 30,
            'allowed_payment_methods' => ['bank_transfer'],
            'portal_catalog_mode' => 'restricted',
            'portal_product_ids' => [$product->hash_id],
            'product_warranties' => [[
                'product_id' => $product->hash_id,
                'label' => '12-month standard',
                'warranty_duration_days' => 365,
                'warranty_start_basis' => 'delivery',
            ]],
            'warranty_duration_days' => 0,
            'warranty_report_window_days' => 0,
        ], $overrides);
    }

    public function test_delivery_location_requires_a_map_pin_and_returns_the_saved_coordinates(): void
    {
        $actor = $this->salesUser();

        $this->actingAs($actor)
            ->postJson('/api/v1/crm/customers', $this->customerPayload())
            ->assertCreated()
            ->assertJsonPath('data.contact_person_role', 'Sales Officer')
            ->assertJsonPath('data.delivery_latitude', '14.2860000')
            ->assertJsonPath('data.delivery_longitude', '120.9345000')
            ->assertJsonPath('data.portal_catalog_mode', 'restricted');

        $this->actingAs($actor)
            ->postJson('/api/v1/crm/customers', $this->customerPayload([
                'name' => 'Honda Missing Pin',
                'email' => 'missing-pin@example.test',
                'delivery_latitude' => null,
                'delivery_longitude' => null,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_latitude', 'delivery_longitude']);
    }

    public function test_new_customer_requires_a_pin_even_before_a_delivery_address_is_entered(): void
    {
        $this->actingAs($this->salesUser())
            ->postJson('/api/v1/crm/customers', [
                'name' => 'Honda Location Pending',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_latitude', 'delivery_longitude']);
    }

    public function test_customer_creation_rejects_a_case_insensitive_duplicate_name(): void
    {
        Customer::factory()->create(['name' => 'Honda Demo Corporation']);

        $this->actingAs($this->salesUser())
            ->postJson('/api/v1/crm/customers', $this->customerPayload([
                'name' => '  hONDA   dEMO cORPORATION  ',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(1, Customer::query()->count());
    }

    public function test_customer_write_service_rechecks_normalized_duplicates_inside_the_transaction(): void
    {
        Customer::factory()->create(['name' => 'Honda Transaction Lock Demo']);
        $payload = Customer::factory()->make(['name' => '  hONDA   transaction lock demo  '])->toArray();

        try {
            app(CustomerService::class)->create($payload);
            $this->fail('The write service must reject a normalized duplicate after acquiring its name lock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        $this->assertSame(1, Customer::query()->whereRaw("LOWER(REGEXP_REPLACE(BTRIM(name), '[[:space:]]+', ' ', 'g')) = ?", ['honda transaction lock demo'])->count());
    }

    public function test_customer_name_check_returns_exact_normalized_matches_for_step_one(): void
    {
        Customer::factory()->create(['name' => 'Honda Demo Corporation']);

        $this->actingAs($this->salesUser())
            ->getJson('/api/v1/crm/customers/name-check?name='.urlencode('  HONDA   demo corporation  '))
            ->assertOk()
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.matches.0.name', 'Honda Demo Corporation');
    }

    public function test_customer_creation_rejects_placeholder_names_and_malformed_contact_information(): void
    {
        $this->actingAs($this->salesUser())
            ->postJson('/api/v1/crm/customers', $this->customerPayload([
                'name' => 'Customer 1',
                'contact_person' => '<script>alert(1)</script>',
                'email' => 'not-an-email',
                'phone' => '12345',
                'delivery_contact_phone' => 'phone number',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'contact_person', 'email', 'phone', 'delivery_contact_phone']);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_customer_text_is_trimmed_and_normalized_before_it_is_saved(): void
    {
        $this->actingAs($this->salesUser())
            ->postJson('/api/v1/crm/customers', $this->customerPayload([
                'name' => '  Honda   Demo   Corporation  ',
                'contact_person' => '  Mika   Santos  ',
                'email' => '  MIKA.SANTOS@HONDA.COM.PH  ',
                'address' => "  Honda Plant,\n   Santa Rosa, Laguna  ",
            ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Honda Demo Corporation')
            ->assertJsonPath('data.contact_person', 'Mika Santos')
            ->assertJsonPath('data.email', 'mika.santos@honda.com.ph')
            ->assertJsonPath('data.address', "Honda Plant,\nSanta Rosa, Laguna");
    }

    public function test_customer_updates_reject_placeholder_and_duplicate_names(): void
    {
        $actor = $this->salesUser();
        $existing = Customer::factory()->create(['name' => 'Honda Philippines Inc.']);
        $changing = Customer::factory()->create(['name' => 'Laguna Vehicle Parts Inc.']);

        $this->actingAs($actor)
            ->putJson("/api/v1/crm/customers/{$changing->hash_id}", ['name' => 'HONDA   PHILIPPINES INC.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($actor)
            ->putJson("/api/v1/crm/customers/{$changing->hash_id}", ['name' => 'Company name'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame('Laguna Vehicle Parts Inc.', $changing->fresh()->name);
        $this->assertSame('Honda Philippines Inc.', $existing->fresh()->name);
    }

    public function test_delivery_coordinates_must_be_in_geographic_bounds(): void
    {
        $this->actingAs($this->salesUser())
            ->postJson('/api/v1/crm/customers', $this->customerPayload([
                'delivery_latitude' => '90.0001',
                'delivery_longitude' => '-180.0001',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_latitude', 'delivery_longitude']);
    }

    public function test_customer_can_be_created_without_a_blanket_warranty_before_product_terms_are_configured(): void
    {
        $this->actingAs($this->salesUser())
            ->postJson('/api/v1/crm/customers', $this->customerPayload([
                'name' => 'Honda Warranty Options Demo',
                'email' => 'warranty-options@example.test',
                'warranty_duration_days' => null,
                'warranty_report_window_days' => null,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.warranty_duration_days', null)
            ->assertJsonPath('data.warranty_report_window_days', null);
    }

    public function test_customer_creation_saves_selected_products_and_multiple_warranty_options_per_product(): void
    {
        $actor = $this->salesUser();
        $lamp = Product::factory()->create(['part_number' => 'PN-900', 'name' => 'Rear lamp assembly']);
        $harness = Product::factory()->create(['part_number' => 'PN-220', 'name' => 'Body wiring harness']);

        $response = $this->actingAs($actor)->postJson('/api/v1/crm/customers', $this->customerPayload([
            'name' => 'Honda Product Setup Demo',
            'email' => 'product-setup@example.test',
            'portal_catalog_mode' => 'restricted',
            'portal_product_ids' => [$lamp->hash_id, $harness->hash_id],
            'product_order_limits' => [
                ['product_id' => $lamp->hash_id, 'minimum_order_quantity' => 20, 'maximum_order_quantity' => 300],
                ['product_id' => $harness->hash_id, 'minimum_order_quantity' => 2, 'maximum_order_quantity' => null],
            ],
            'product_warranties' => [
                [
                    'product_id' => $lamp->hash_id,
                    'label' => '6-month limited',
                    'warranty_duration_days' => 180,
                    'warranty_start_basis' => 'delivery',
                    'warranty_covered_issues' => ['manufacturing_defect', 'shipping_damage'],
                    'warranty_remedies' => ['repair', 'replace'],
                ],
                [
                    'product_id' => $lamp->hash_id,
                    'label' => '12-month standard',
                    'warranty_duration_days' => 365,
                    'warranty_start_basis' => 'delivery',
                    'warranty_covered_issues' => ['manufacturing_defect'],
                    'warranty_remedies' => ['replace'],
                ],
                [
                    'product_id' => $harness->hash_id,
                    'label' => '6-month limited',
                    'warranty_duration_days' => 180,
                    'warranty_start_basis' => 'invoice',
                    'warranty_covered_issues' => ['manufacturing_defect'],
                    'warranty_remedies' => ['replace'],
                ],
            ],
        ]))->assertCreated()->assertJsonPath('data.portal_catalog_mode', 'restricted');

        $customer = Customer::query()->where('name', 'Honda Product Setup Demo')->firstOrFail();
        $this->assertTrue($lamp->portalCustomers()->whereKey($customer->id)->exists());
        $this->assertTrue($harness->portalCustomers()->whereKey($customer->id)->exists());
        $this->assertDatabaseHas('customer_product_access', [
            'customer_id' => $customer->id,
            'product_id' => $lamp->id,
            'minimum_order_quantity' => '20.000',
            'maximum_order_quantity' => '300.000',
        ]);
        $this->assertDatabaseHas('customer_product_access', [
            'customer_id' => $customer->id,
            'product_id' => $harness->id,
            'minimum_order_quantity' => '2.000',
            'maximum_order_quantity' => null,
        ]);
        $this->assertDatabaseHas('customer_product_warranty_options', [
            'customer_id' => $customer->id,
            'product_id' => $lamp->id,
            'label' => '12-month standard',
            'warranty_duration_days' => 365,
        ]);

        $this->getJson("/api/v1/crm/customers/{$response->json('data.id')}/warranty-terms")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_customer_creation_rejects_a_warranty_for_a_product_not_in_the_ordering_setup(): void
    {
        $allowed = Product::factory()->create();
        $unselected = Product::factory()->create();

        $this->actingAs($this->salesUser())
            ->postJson('/api/v1/crm/customers', $this->customerPayload([
                'name' => 'Honda Invalid Product Setup',
                'portal_catalog_mode' => 'restricted',
                'portal_product_ids' => [$allowed->hash_id],
                'product_warranties' => [[
                    'product_id' => $unselected->hash_id,
                    'label' => 'Warranty option',
                    'warranty_duration_days' => 365,
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_warranties.0.product_id']);
    }

    public function test_customer_creation_rejects_duplicate_values_within_one_product_warranty_option(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->salesUser())
            ->postJson('/api/v1/crm/customers', $this->customerPayload([
                'name' => 'Honda Duplicate Warranty Values',
                'portal_catalog_mode' => 'restricted',
                'portal_product_ids' => [$product->hash_id],
                'product_warranties' => [[
                    'product_id' => $product->hash_id,
                    'label' => 'Warranty option',
                    'warranty_covered_issues' => ['manufacturing_defect', 'manufacturing_defect'],
                    'warranty_remedies' => ['repair'],
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_warranties.0.warranty_covered_issues.1']);
    }

    public function test_legacy_customers_without_a_delivery_location_remain_valid_and_nullable(): void
    {
        $customer = Customer::factory()->create([
            'delivery_address' => null,
        ]);

        $this->actingAs($this->salesUser())
            ->getJson("/api/v1/crm/customers/{$customer->hash_id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['contact_person_role', 'delivery_latitude', 'delivery_longitude']])
            ->assertJsonPath('data.delivery_latitude', null)
            ->assertJsonPath('data.delivery_longitude', null)
            ->assertJsonPath('data.contact_person_role', null);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'delivery_latitude' => null,
            'delivery_longitude' => null,
        ]);
    }

    public function test_updates_reject_a_partial_pin_and_a_changed_address_without_both_coordinates(): void
    {
        $actor = $this->salesUser();
        $customer = Customer::factory()->create([
            'delivery_address' => 'Old address',
        ]);

        $this->actingAs($actor)
            ->putJson("/api/v1/crm/customers/{$customer->hash_id}", ['delivery_latitude' => '14.3000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_longitude']);

        $this->actingAs($actor)
            ->putJson("/api/v1/crm/customers/{$customer->hash_id}", ['delivery_address' => 'New address'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_latitude', 'delivery_longitude']);

        $this->assertSame('Old address', $customer->fresh()->delivery_address);
    }

    public function test_customer_creation_requires_contact_billing_payment_and_product_terms(): void
    {
        $actor = $this->salesUser();
        $invalidCases = [
            ['contact_person', ['contact_person' => null]],
            ['contact_person_role', ['contact_person_role' => null]],
            ['phone', ['email' => null, 'phone' => null]],
            ['address', ['address' => null]],
            ['delivery_address', ['delivery_address' => null]],
            ['payment_terms_days', ['payment_terms_days' => null]],
            ['allowed_payment_methods', ['allowed_payment_methods' => []]],
            ['portal_product_ids', ['portal_product_ids' => [], 'product_warranties' => []]],
            ['product_warranties', ['product_warranties' => []]],
        ];

        foreach ($invalidCases as $index => [$field, $overrides]) {
            $this->actingAs($actor)
                ->postJson('/api/v1/crm/customers', $this->customerPayload([
                    'name' => 'Honda Required Fields '.$index,
                    ...$overrides,
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }
    }

    public function test_customer_creation_requires_a_warranty_for_each_selected_product(): void
    {
        $first = Product::factory()->create();
        $second = Product::factory()->create();

        $this->actingAs($this->salesUser())
            ->postJson('/api/v1/crm/customers', $this->customerPayload([
                'name' => 'Honda Incomplete Warranty Setup',
                'email' => 'incomplete-warranty@example.test',
                'portal_product_ids' => [$first->hash_id, $second->hash_id],
                'product_warranties' => [[
                    'product_id' => $first->hash_id,
                    'label' => 'Standard warranty',
                    'warranty_duration_days' => 365,
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_warranties']);

        $this->assertDatabaseMissing('customers', ['name' => 'Honda Incomplete Warranty Setup']);
    }
}
