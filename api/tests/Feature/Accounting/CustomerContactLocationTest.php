<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        return array_merge([
            'name' => 'Honda Demo Corporation',
            'contact_person' => 'Mika Sato',
            'contact_person_role' => 'Sales Officer',
            'email' => 'mika@example.test',
            'delivery_address' => 'Honda Plant, Santa Rosa, Laguna',
            'delivery_latitude' => '14.2860000',
            'delivery_longitude' => '120.9345000',
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

    public function test_contact_person_role_is_optional(): void
    {
        $this->actingAs($this->salesUser())
            ->postJson('/api/v1/crm/customers', $this->customerPayload([
                'contact_person_role' => null,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.contact_person_role', null);
    }
}
