<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetDriverHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_scheduled_delivery_can_be_assigned_to_an_active_driver_and_available_vehicle(): void
    {
        $operator = $this->operator();
        $driver = $this->driver();
        $vehicle = $this->vehicle();
        $delivery = $this->delivery($operator);

        $this->actingAs($operator)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/assignment", [
                'driver_id' => $driver->hash_id,
                'vehicle_id' => $vehicle->hash_id,
                'reason' => 'Assigned for the confirmed customer route.',
            ])
            ->assertOk()
            ->assertJsonPath('data.driver.id', $driver->hash_id)
            ->assertJsonPath('data.vehicle.id', $vehicle->hash_id);

        $fresh = $delivery->fresh();
        $this->assertSame($driver->id, $fresh->driver_id);
        $this->assertSame($vehicle->id, $fresh->vehicle_id);

        $audit = AuditLog::query()
            ->where('model_type', $delivery->getMorphClass())
            ->where('model_id', $delivery->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($operator->id, $audit->user_id);
        $this->assertStringContainsString('confirmed customer route', (string) $audit->reason);
    }

    public function test_assignment_rejects_a_vehicle_in_maintenance(): void
    {
        $operator = $this->operator();
        $driver = $this->driver();
        $vehicle = $this->vehicle('maintenance');
        $delivery = $this->delivery($operator);

        $this->actingAs($operator)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/assignment", [
                'driver_id' => $driver->hash_id,
                'vehicle_id' => $vehicle->hash_id,
                'reason' => 'Attempted maintenance assignment.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', "Vehicle {$vehicle->plate_number} is not available for assignment.");

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'driver_id' => null,
            'vehicle_id' => null,
        ]);
    }

    public function test_assignment_rejects_an_inactive_driver(): void
    {
        $operator = $this->operator();
        $driver = $this->driver();
        $driver->update(['is_active' => false]);
        $delivery = $this->delivery($operator);

        $this->actingAs($operator)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/assignment", [
                'driver_id' => $driver->hash_id,
                'vehicle_id' => $this->vehicle()->hash_id,
                'reason' => 'Attempted inactive driver assignment.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('driver_id');
    }

    public function test_loading_requires_a_vehicle_assignment(): void
    {
        $driver = $this->driver();
        $delivery = $this->delivery($driver, driver: $driver);

        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$delivery->hash_id}/status", ['status' => 'loading'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A dispatchable vehicle must be assigned before loading.');
    }

    public function test_future_delivery_cannot_enter_loading(): void
    {
        $driver = $this->driver();
        $delivery = $this->delivery($driver, driver: $driver, vehicle: $this->vehicle(), scheduledDate: now()->addDay()->toDateString());

        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$delivery->hash_id}/status", ['status' => 'loading'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('scheduled', $delivery->fresh()->status->value);
    }

    public function test_active_delivery_blocks_vehicle_status_change_and_archive(): void
    {
        $admin = $this->systemAdmin();
        $vehicle = $this->vehicle();
        $delivery = $this->delivery($admin, vehicle: $vehicle);

        $this->actingAs($admin)
            ->patchJson("/api/v1/supply-chain/vehicles/{$vehicle->hash_id}", ['status' => 'maintenance'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/supply-chain/vehicles/{$vehicle->hash_id}")
            ->assertStatus(422);

        $this->assertNotNull($vehicle->fresh());
        $this->assertNull($delivery->fresh()->deleted_at);
    }

    public function test_archived_vehicle_can_be_listed_with_trashed_and_restored(): void
    {
        $admin = $this->systemAdmin();
        $vehicle = $this->vehicle();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/supply-chain/vehicles/{$vehicle->hash_id}")
            ->assertNoContent();

        $this->actingAs($admin)
            ->getJson('/api/v1/supply-chain/vehicles?trashed=only')
            ->assertOk()
            ->assertJsonPath('data.0.id', $vehicle->hash_id);

        $this->actingAs($admin)
            ->patchJson("/api/v1/supply-chain/vehicles/{$vehicle->hash_id}/restore")
            ->assertOk();

        $this->assertNull($vehicle->fresh()->deleted_at);
    }

    public function test_driver_api_is_blocked_when_supply_chain_is_disabled(): void
    {
        $driver = $this->driver();
        app(SettingsService::class)->set('modules.supply_chain', false, 'modules');

        $this->actingAs($driver)
            ->getJson('/api/v1/driver/deliveries')
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_disabled');
    }

    public function test_driver_response_excludes_internal_delivery_and_accounting_fields(): void
    {
        $driver = $this->driver();
        $delivery = $this->delivery($driver, driver: $driver, vehicle: $this->vehicle());
        $delivery->update(['notes' => 'Internal dispatch note']);

        $this->actingAs($driver)
            ->getJson("/api/v1/driver/deliveries/{$delivery->hash_id}")
            ->assertOk()
            ->assertJsonMissingPath('data.notes')
            ->assertJsonMissingPath('data.invoice')
            ->assertJsonMissingPath('data.items')
            ->assertJsonMissingPath('data.shipment_lot')
            ->assertJsonMissingPath('data.receipt_photo_url');
    }

    private function operator(): User
    {
        $role = Role::create([
            'name' => 'Fleet operator '.uniqid(),
            'slug' => 'fleet_operator_'.uniqid(),
            'description' => 'Fleet assignment test operator',
        ]);
        $permission = Permission::firstOrCreate(
            ['slug' => 'supply_chain.deliveries.create'],
            ['name' => 'Create Deliveries', 'module' => 'supply_chain'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function systemAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
    }

    private function driver(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'driver')->value('id'),
            'is_active' => true,
        ]);
    }

    private function vehicle(string $status = 'available'): Vehicle
    {
        return Vehicle::create([
            'plate_number' => 'FD-'.substr(uniqid(), -8),
            'name' => 'Fleet test vehicle '.uniqid(),
            'vehicle_type' => 'van',
            'capacity_kg' => '1000.00',
            'status' => $status,
        ]);
    }

    private function delivery(
        User $creator,
        ?User $driver = null,
        ?Vehicle $vehicle = null,
        ?string $scheduledDate = null,
    ): Delivery {
        $customer = Customer::create([
            'name' => 'Fleet test customer '.uniqid(),
            'is_active' => true,
        ]);
        $order = SalesOrder::create([
            'so_number' => 'SO-FD-'.substr(uniqid(), -8),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '100.00',
            'vat_amount' => '12.00',
            'total_amount' => '112.00',
            'status' => 'confirmed',
            'created_by' => $creator->id,
        ]);

        return Delivery::create([
            'delivery_number' => 'DLV-FD-'.substr(uniqid(), -8),
            'sales_order_id' => $order->id,
            'driver_id' => $driver?->id,
            'vehicle_id' => $vehicle?->id,
            'scheduled_date' => $scheduledDate ?? now()->toDateString(),
            'status' => 'scheduled',
            'created_by' => $creator->id,
        ]);
    }
}
