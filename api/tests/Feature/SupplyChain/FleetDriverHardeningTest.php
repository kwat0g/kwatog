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
use Illuminate\Support\Facades\Storage;
use Tests\Support\PreparesDeliveryShipment;
use Tests\Support\ReceivesProductionOutput;
use Tests\TestCase;

class FleetDriverHardeningTest extends TestCase
{
    use ReceivesProductionOutput;
    use PreparesDeliveryShipment;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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

    public function test_driver_options_omit_a_driver_with_an_active_delivery(): void
    {
        $operator = $this->operator();
        $busyDriver = $this->driver();
        $freeDriver = $this->driver();
        $delivery = $this->delivery($operator);

        $this->actingAs($operator)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/assignment", [
                'driver_id' => $busyDriver->hash_id,
                'vehicle_id' => $this->vehicle()->hash_id,
                'reason' => 'Assigned to the current route.',
            ])
            ->assertOk();

        $this->actingAs($busyDriver)
            ->patchJson("/api/v1/driver/deliveries/{$delivery->hash_id}/status", ['status' => 'loading'])
            ->assertOk();

        $response = $this->actingAs($operator)
            ->getJson('/api/v1/supply-chain/deliveries/driver-options')
            ->assertOk();
        $driverIds = array_column($response->json('data'), 'id');

        $this->assertNotContains($busyDriver->hash_id, $driverIds);
        $this->assertContains($freeDriver->hash_id, $driverIds);
    }

    public function test_assignment_api_rejects_a_driver_with_an_active_delivery_without_writing(): void
    {
        $operator = $this->operator();
        $driver = $this->driver();
        $current = $this->delivery($operator);
        $next = $this->delivery($operator);

        $this->actingAs($operator)
            ->patchJson("/api/v1/supply-chain/deliveries/{$current->hash_id}/assignment", [
                'driver_id' => $driver->hash_id,
                'vehicle_id' => $this->vehicle()->hash_id,
                'reason' => 'Assigned to the current route.',
            ])
            ->assertOk();
        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$current->hash_id}/status", ['status' => 'loading'])
            ->assertOk();

        $this->actingAs($operator)
            ->patchJson("/api/v1/supply-chain/deliveries/{$next->hash_id}/assignment", [
                'driver_id' => $driver->hash_id,
                'vehicle_id' => $this->vehicle()->hash_id,
                'reason' => 'Attempted overlapping assignment.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Driver is already assigned to another active delivery.');

        $this->assertDatabaseHas('deliveries', [
            'id' => $next->id,
            'status' => 'scheduled',
            'driver_id' => null,
            'vehicle_id' => null,
        ]);
    }

    public function test_vehicle_capacity_rejects_more_than_two_decimal_places(): void
    {
        $response = $this->actingAs($this->systemAdmin())
            ->postJson('/api/v1/supply-chain/vehicles', [
                'plate_number' => 'PREC-'.substr(uniqid(), -8),
                'name' => 'Precision vehicle',
                'vehicle_type' => 'van',
                'capacity_kg' => '1.999',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('capacity_kg');
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_vehicle_capacity_overflow_is_validation_error_without_writing(): void
    {
        $response = $this->actingAs($this->systemAdmin())
            ->postJson('/api/v1/supply-chain/vehicles', [
                'plate_number' => 'LIMIT-'.substr(uniqid(), -8),
                'name' => 'Limit vehicle',
                'vehicle_type' => 'van',
                'capacity_kg' => '100000000',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('capacity_kg');
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_vehicle_capacity_accepts_the_database_maximum(): void
    {
        $plate = 'MAX-'.substr(uniqid(), -8);

        $this->actingAs($this->systemAdmin())
            ->postJson('/api/v1/supply-chain/vehicles', [
                'plate_number' => $plate,
                'name' => 'Maximum capacity vehicle',
                'vehicle_type' => 'van',
                'capacity_kg' => '99999999.99',
            ])
            ->assertCreated();

        $this->assertSame('99999999.99', (string) \Illuminate\Support\Facades\DB::table('vehicles')
            ->where('plate_number', $plate)
            ->value('capacity_kg'));
    }

    public function test_vehicle_capacity_update_rejects_extra_precision_without_mutating(): void
    {
        $admin = $this->systemAdmin();
        $vehicle = $this->vehicle();

        $this->actingAs($admin)
            ->patchJson("/api/v1/supply-chain/vehicles/{$vehicle->hash_id}", ['capacity_kg' => '1.999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('capacity_kg');

        $this->assertSame('1000.00', $vehicle->fresh()->capacity_kg);
    }

    public function test_vehicle_capacity_update_rejects_overflow_without_mutating(): void
    {
        $admin = $this->systemAdmin();
        $vehicle = $this->vehicle();

        $this->actingAs($admin)
            ->patchJson("/api/v1/supply-chain/vehicles/{$vehicle->hash_id}", ['capacity_kg' => '100000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('capacity_kg');

        $this->assertSame('1000.00', $vehicle->fresh()->capacity_kg);
    }

    public function test_vehicle_list_rejects_non_positive_page_size_and_keeps_upper_cap(): void
    {
        $admin = $this->systemAdmin();

        foreach (['-1', '0', ['2']] as $perPage) {
            $this->actingAs($admin)
                ->getJson('/api/v1/supply-chain/vehicles?'.http_build_query(['per_page' => $perPage]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('per_page');
        }

        $this->actingAs($admin)
            ->getJson('/api/v1/supply-chain/vehicles?per_page=99999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
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

    public function test_archived_vehicle_stays_visible_in_delivery_history(): void
    {
        $operator = $this->operator();
        $driver = $this->driver();
        $admin = $this->systemAdmin();
        $vehicle = $this->vehicle();
        [$delivery] = $this->dispatchableDelivery($operator, '3.00');
        $delivery->forceFill(['scheduled_date' => now()->toDateString()])->save();
        $warehouse = User::factory()->withRole('warehouse_staff')->create(['is_active' => true]);
        $this->reserveDeliveryStock($delivery, $warehouse);

        $this->actingAs($operator)
            ->patchJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/assignment", [
                'driver_id' => $driver->hash_id,
                'vehicle_id' => $vehicle->hash_id,
                'reason' => 'Assigned for the recorded route.',
            ])
            ->assertOk();
        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$delivery->hash_id}/status", ['status' => 'loading'])
            ->assertOk();
        $this->prepareShipmentForDispatch($delivery->fresh());
        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$delivery->hash_id}/status", ['status' => 'in_transit'])
            ->assertOk();
        $this->attachDeliveryEvidence($delivery);
        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$delivery->hash_id}/status", ['status' => 'delivered'])
            ->assertOk();

        $this->assertSame('delivered', $delivery->fresh()->status->value);
        $this->assertSame('available', $vehicle->fresh()->status);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/supply-chain/vehicles/{$vehicle->hash_id}")
            ->assertNoContent();
        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'vehicle_id' => $vehicle->id,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.vehicle.id', $vehicle->hash_id)
            ->assertJsonPath('data.vehicle.plate_number', $vehicle->plate_number);
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
        [$delivery, $item] = $this->dispatchableDelivery($driver, '3.00');
        $delivery->forceFill(['driver_id' => $driver->id, 'vehicle_id' => $this->vehicle()->id])->save();
        $delivery->update(['notes' => 'Internal dispatch note']);

        $this->actingAs($driver)
            ->getJson("/api/v1/driver/deliveries/{$delivery->hash_id}")
            ->assertOk()
            ->assertJsonMissingPath('data.notes')
            ->assertJsonMissingPath('data.invoice')
            ->assertJsonMissingPath('data.shipment_lot')
            ->assertJsonMissingPath('data.receipt_photo_url')
            // The driver needs the load lines for the outcome form, but only as the
            // narrow projection: no costing, stock or reservation internals.
            ->assertJsonPath('data.items.0.product.part_number', $item->code)
            ->assertJsonMissingPath('data.items.0.unit_price')
            ->assertJsonMissingPath('data.items.0.stock_movement_id')
            ->assertJsonMissingPath('data.items.0.reserved_quantity');
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
