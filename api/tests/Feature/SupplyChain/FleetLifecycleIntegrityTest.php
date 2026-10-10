<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Models\AuditLog;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryReschedule;
use App\Modules\SupplyChain\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PreparesDeliveryShipment;
use Tests\Support\ReceivesProductionOutput;
use Tests\TestCase;

/** Regression coverage for fleet assignments, lifecycle, permissions, and history. */
class FleetLifecycleIntegrityTest extends TestCase
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

    /* ───────────────────────── A. assignment integrity ───────────────────────── */

    public function test_a1_driver_cannot_be_scheduled_for_two_same_day_deliveries(): void
    {
        $op = $this->operator();
        $driver = $this->driver();
        $v1 = $this->vehicle();
        $v2 = $this->vehicle();
        $d1 = $this->delivery($op);
        $d2 = $this->delivery($op);

        $a1 = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d1->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v1->hash_id, 'reason' => 'probe first route assignment',
        ]);
        $a1->assertOk();
        $this->assertSame($driver->id, $d1->fresh()->driver_id);
        $this->assertSame($v1->id, $d1->fresh()->vehicle_id);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $op->id,
            'action' => 'updated',
            'model_type' => $d1->getMorphClass(),
            'model_id' => $d1->id,
        ]);

        $auditCount = DB::table('audit_logs')->where('model_type', $d2->getMorphClass())
            ->where('model_id', $d2->id)->count();
        $a2 = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d2->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v2->hash_id, 'reason' => 'probe second route assignment',
        ]);
        $a2->assertUnprocessable()
            ->assertJsonPath('message', 'Driver is already assigned to another scheduled delivery on that date.');
        $this->assertNull($d2->fresh()->driver_id);
        $this->assertNull($d2->fresh()->vehicle_id);
        $this->assertSame($auditCount, DB::table('audit_logs')->where('model_type', $d2->getMorphClass())
            ->where('model_id', $d2->id)->count());
    }

    public function test_a2_vehicle_cannot_be_scheduled_for_two_same_day_deliveries(): void
    {
        $op = $this->operator();
        $dr1 = $this->driver();
        $dr2 = $this->driver();
        $v = $this->vehicle();
        $d1 = $this->delivery($op);
        $d2 = $this->delivery($op);

        $a1 = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d1->hash_id}/assignment", [
            'driver_id' => $dr1->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe shared vehicle first',
        ]);
        $a1->assertOk();
        $this->assertSame($v->id, $d1->fresh()->vehicle_id);
        $auditCount = DB::table('audit_logs')->where('model_type', $d2->getMorphClass())
            ->where('model_id', $d2->id)->count();
        $a2 = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d2->hash_id}/assignment", [
            'driver_id' => $dr2->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe shared vehicle second',
        ]);
        $a2->assertUnprocessable()
            ->assertJsonPath('message', "Vehicle {$v->plate_number} is already assigned to another scheduled delivery on that date.");
        $this->assertNull($d2->fresh()->vehicle_id);
        $this->assertNull($d2->fresh()->driver_id);
        $this->assertSame($auditCount, DB::table('audit_logs')->where('model_type', $d2->getMorphClass())
            ->where('model_id', $d2->id)->count());
    }

    public function test_reschedule_rejects_driver_or_vehicle_date_conflicts_without_writes(): void
    {
        $op = $this->operator();
        $driver = $this->driver();
        $otherDriver = $this->driver();
        $vehicle = $this->vehicle();
        $otherVehicle = $this->vehicle();
        $first = $this->delivery($op);
        $second = $this->delivery($op);
        $third = $this->delivery($op);
        $fourth = $this->delivery($op);
        $tomorrow = now()->addDay()->toDateString();
        $dayAfterTomorrow = now()->addDays(2)->toDateString();
        $second->forceFill(['scheduled_date' => $tomorrow])->save();
        $third->forceFill(['scheduled_date' => $tomorrow])->save();
        $fourth->forceFill(['scheduled_date' => $dayAfterTomorrow])->save();

        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$first->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $vehicle->hash_id, 'reason' => 'first assignment',
        ])->assertOk();
        $this->assertSame(now()->toDateString(), $first->fresh()->scheduled_date->toDateString());
        $this->assertSame($driver->id, $first->fresh()->driver_id);

        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$second->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $otherVehicle->hash_id, 'reason' => 'tomorrow assignment',
        ])->assertOk();
        $this->assertSame($tomorrow, $second->fresh()->scheduled_date->toDateString());

        $secondAuditCount = DB::table('audit_logs')->where('model_type', $second->getMorphClass())
            ->where('model_id', $second->id)->count();
        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$second->hash_id}/reschedule", [
            'scheduled_date' => now()->toDateString(), 'reason' => 'move to today',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Driver is already assigned to another scheduled delivery on that date.');
        $this->assertSame($tomorrow, $second->fresh()->scheduled_date->toDateString());
        $this->assertNull($second->fresh()->original_scheduled_date);
        $this->assertSame(0, $second->fresh()->reschedule_count);
        $this->assertSame($secondAuditCount, DB::table('audit_logs')->where('model_type', $second->getMorphClass())
            ->where('model_id', $second->id)->count());
        $this->assertSame(0, DeliveryReschedule::query()->where('delivery_id', $second->id)->count());

        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$third->hash_id}/assignment", [
            'driver_id' => $otherDriver->hash_id, 'vehicle_id' => $vehicle->hash_id, 'reason' => 'tomorrow vehicle assignment',
        ])->assertOk();
        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$fourth->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $vehicle->hash_id, 'reason' => 'tomorrow vehicle assignment',
        ])->assertOk();
        $fourthAuditCount = DB::table('audit_logs')->where('model_type', $fourth->getMorphClass())
            ->where('model_id', $fourth->id)->count();
        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$fourth->hash_id}/reschedule", [
            'scheduled_date' => now()->toDateString(), 'reason' => 'move into vehicle conflict',
        ])->assertUnprocessable()
            ->assertJsonPath('message', "Vehicle {$vehicle->plate_number} is already assigned to another scheduled delivery on that date.");
        $this->assertSame($dayAfterTomorrow, $fourth->fresh()->scheduled_date->toDateString());
        $this->assertNull($fourth->fresh()->original_scheduled_date);
        $this->assertSame(0, $fourth->fresh()->reschedule_count);
        $this->assertSame($fourthAuditCount, DB::table('audit_logs')->where('model_type', $fourth->getMorphClass())
            ->where('model_id', $fourth->id)->count());
        $this->assertSame(0, DeliveryReschedule::query()->where('delivery_id', $fourth->id)->count());
    }

    public function test_assignment_rejects_archived_vehicle_and_driver(): void
    {
        $op = $this->operator();
        $driver = $this->driver();
        $v = $this->vehicle();
        $v->delete();
        $d = $this->delivery($op);
        $deliveryAuditCount = DB::table('audit_logs')->where('model_type', $d->getMorphClass())
            ->where('model_id', $d->id)->count();

        $r = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe archived vehicle assign',
        ]);
        $r->assertUnprocessable()->assertJsonPath('message', 'Assigned vehicle not found.');
        $this->assertNull($d->fresh()->driver_id);
        $this->assertNull($d->fresh()->vehicle_id);
        $this->assertSame($deliveryAuditCount, DB::table('audit_logs')->where('model_type', $d->getMorphClass())
            ->where('model_id', $d->id)->count());

        // Inactive driver already covered; probe a soft-deleted driver user.
        $driver2 = $this->driver();
        $v2 = $this->vehicle();
        $driver2->delete();
        $d2 = $this->delivery($op);
        $secondAuditCount = DB::table('audit_logs')->where('model_type', $d2->getMorphClass())
            ->where('model_id', $d2->id)->count();
        $r2 = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d2->hash_id}/assignment", [
            'driver_id' => $driver2->hash_id, 'vehicle_id' => $v2->hash_id, 'reason' => 'probe archived driver assign',
        ]);
        $r2->assertUnprocessable()->assertJsonValidationErrors('driver_id');
        $this->assertNull($d2->fresh()->driver_id);
        $this->assertNull($d2->fresh()->vehicle_id);
        $this->assertSame($secondAuditCount, DB::table('audit_logs')->where('model_type', $d2->getMorphClass())
            ->where('model_id', $d2->id)->count());
    }

    public function test_e1_vehicle_identity_cannot_change_after_a_delivery_references_it(): void
    {
        $admin = $this->systemAdmin();
        $op = $this->operator();
        $driver = $this->driver();
        $vehicle = $this->vehicle();
        $delivery = $this->delivery($op, $driver, $vehicle);
        $path = "/api/v1/supply-chain/deliveries/{$delivery->hash_id}";
        $before = $this->actingAs($admin)->getJson($path)->assertOk()->json('data.vehicle');
        $auditCount = DB::table('audit_logs')->where('model_type', $vehicle->getMorphClass())
            ->where('model_id', $vehicle->id)->count();

        $this->actingAs($admin)->patchJson("/api/v1/supply-chain/vehicles/{$vehicle->hash_id}", [
            'plate_number' => 'REWRITTEN-1',
            'name' => 'Rewritten historical vehicle',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Vehicle identity cannot be changed after it has been assigned to a delivery.');

        $this->assertSame($vehicle->plate_number, $vehicle->fresh()->plate_number);
        $this->assertSame($vehicle->name, $vehicle->fresh()->name);
        $this->assertSame($before, $this->actingAs($admin)->getJson($path)->assertOk()->json('data.vehicle'));
        $this->assertSame($auditCount, DB::table('audit_logs')->where('model_type', $vehicle->getMorphClass())
            ->where('model_id', $vehicle->id)->count());
    }

    public function test_driver_options_exclude_busy_and_archived_drivers(): void
    {
        $op = $this->operator();
        $busy = $this->driver();
        $free = $this->driver();
        $archived = $this->driver();
        $archived->delete();
        $v = $this->vehicle();
        $d = $this->delivery($op);
        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d->hash_id}/assignment", [
            'driver_id' => $busy->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe busy driver option leak',
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->actingAs($busy)
            ->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'loading'])
            ->assertOk()->assertJsonPath('data.status', 'loading');
        $this->assertSame('loading', $d->fresh()->status->value);

        $opts = $this->actingAs($op)->getJson('/api/v1/supply-chain/deliveries/driver-options');
        $ids = collect($opts->json('data') ?? [])->pluck('id')->all();
        $opts->assertOk()->assertJsonStructure(['data']);
        $this->assertContains($free->hash_id, $ids);
        $this->assertNotContains($busy->hash_id, $ids);
        $this->assertNotContains($archived->hash_id, $ids);
    }

    /* ───────────────────── B. vehicle status transition matrix ───────────────────── */

    public function test_b1_vehicle_status_transition_matrix_only_dispatch_can_set_in_use(): void
    {
        $admin = $this->systemAdmin();
        $states = ['available', 'in_use', 'maintenance', 'retired'];
        $vehicleType = (new Vehicle)->getMorphClass();
        foreach ($states as $from) {
            foreach ($states as $to) {
                $v = $this->vehicle($from);
                $auditCount = DB::table('audit_logs')->where('model_type', $vehicleType)
                    ->where('model_id', $v->id)->count();
                $r = $this->actingAs($admin)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}", ['status' => $to]);
                if ($to === 'in_use' && $from !== 'in_use') {
                    $r->assertUnprocessable()
                        ->assertJsonPath('message', 'Vehicle status is managed by delivery dispatch.');
                    $this->assertSame($from, $v->fresh()->status);
                    $this->assertSame($auditCount, DB::table('audit_logs')->where('model_type', $vehicleType)
                        ->where('model_id', $v->id)->count());

                    continue;
                }

                $r->assertOk();
                $this->assertSame($to, $v->fresh()->status);
            }
        }

        $plate = 'CREATE-'.strtoupper(substr(uniqid(), -12));
        $before = Vehicle::query()->count();
        $auditBefore = DB::table('audit_logs')->where('model_type', $vehicleType)->count();
        $this->actingAs($admin)->postJson('/api/v1/supply-chain/vehicles', [
            'plate_number' => $plate,
            'name' => 'Direct in-use creation probe',
            'vehicle_type' => 'van',
            'status' => 'in_use',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Vehicle status is managed by delivery dispatch.');
        $this->assertSame($before, Vehicle::query()->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->where('model_type', $vehicleType)->count());
    }

    public function test_vehicle_retirement_is_blocked_during_delivery_and_allowed_after(): void
    {
        $op = $this->operator();
        $admin = $this->systemAdmin();
        $driver = $this->driver();
        $warehouse = User::factory()->withRole('warehouse_staff')->create(['is_active' => true]);
        $v = $this->vehicle();
        [$d] = $this->dispatchableDelivery($op, '3.00');
        $d->forceFill(['scheduled_date' => now()->toDateString()])->save();
        $this->reserveDeliveryStock($d, $warehouse);
        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe retire mid delivery',
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertSame('scheduled', $d->fresh()->status->value);
        $this->assertSame($driver->id, $d->fresh()->driver_id);
        $this->assertSame($v->id, $d->fresh()->vehicle_id);

        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'loading'])
            ->assertOk()->assertJsonPath('data.status', 'loading');
        $this->assertSame('loading', $d->fresh()->status->value);
        $this->assertSame('in_use', $v->fresh()->status);

        $this->prepareShipmentForDispatch($d->fresh());
        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'in_transit'])
            ->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->assertSame('in_transit', $d->fresh()->status->value);
        $this->assertSame('in_use', $v->fresh()->status);

        $retire = $this->actingAs($admin)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}", ['status' => 'retired']);
        $archive = $this->actingAs($admin)->deleteJson("/api/v1/supply-chain/vehicles/{$v->hash_id}");
        $retire->assertUnprocessable();
        $archive->assertUnprocessable();
        $this->assertSame('in_use', $v->fresh()->status);
        $this->assertNull($v->fresh()->deleted_at);

        // Now finish the delivery and try again.
        $this->attachDeliveryEvidence($d);
        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'delivered'])
            ->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertSame('delivered', $d->fresh()->status->value);
        $this->assertSame('available', $v->fresh()->status);

        $retire2 = $this->actingAs($admin)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}", ['status' => 'retired']);
        $retire2->assertOk()->assertJsonPath('data.status', 'retired');
        $this->assertSame('retired', $v->fresh()->status);

        $react = $this->actingAs($admin)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}", ['status' => 'available']);
        $react->assertOk()->assertJsonPath('data.status', 'available');
        $this->assertSame('available', $v->fresh()->status);
    }

    public function test_archived_vehicle_remains_visible_in_completed_delivery_history(): void
    {
        $op = $this->operator();
        $admin = $this->systemAdmin();
        $driver = $this->driver();
        $warehouse = User::factory()->withRole('warehouse_staff')->create(['is_active' => true]);
        $v = $this->vehicle();
        [$d] = $this->dispatchableDelivery($op, '3.00');
        $d->forceFill(['scheduled_date' => now()->toDateString()])->save();
        $this->reserveDeliveryStock($d, $warehouse);

        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe archive with history',
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertSame($driver->id, $d->fresh()->driver_id);
        $this->assertSame($v->id, $d->fresh()->vehicle_id);

        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'loading'])
            ->assertOk()->assertJsonPath('data.status', 'loading');
        $this->assertSame('loading', $d->fresh()->status->value);
        $this->assertSame('in_use', $v->fresh()->status);

        $this->prepareShipmentForDispatch($d->fresh());
        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'in_transit'])
            ->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->assertSame('in_transit', $d->fresh()->status->value);
        $this->assertSame('in_use', $v->fresh()->status);

        $this->attachDeliveryEvidence($d);
        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'delivered'])
            ->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertSame('delivered', $d->fresh()->status->value);
        $this->assertSame('available', $v->fresh()->status);

        $plate = $v->plate_number;
        $archive = $this->actingAs($admin)->deleteJson("/api/v1/supply-chain/vehicles/{$v->hash_id}");
        $archive->assertNoContent();
        $this->assertNotNull(Vehicle::withTrashed()->findOrFail($v->id)->deleted_at);
        $this->assertSame($v->id, $d->fresh()->vehicle_id);

        $show = $this->actingAs($admin)->getJson("/api/v1/supply-chain/deliveries/{$d->hash_id}");
        $list = $this->actingAs($admin)->getJson('/api/v1/supply-chain/deliveries');
        $drvShow = $this->actingAs($driver)->getJson("/api/v1/driver/deliveries/{$d->hash_id}");
        $drvList = $this->actingAs($driver)->getJson('/api/v1/driver/deliveries?status=delivered');
        $show->assertOk()->assertJsonPath('data.vehicle.id', $v->hash_id);
        $list->assertOk()->assertJsonFragment(['id' => $d->hash_id]);
        $drvShow->assertOk()->assertJsonPath('data.vehicle.id', $v->hash_id);
        $drvList->assertOk()->assertJsonFragment(['id' => $d->hash_id]);

        // Plate re-registration after archive.
        $recreate = $this->actingAs($admin)->postJson('/api/v1/supply-chain/vehicles', [
            'plate_number' => $plate, 'name' => 'Replacement unit', 'vehicle_type' => 'van',
        ]);
        $recreate->assertUnprocessable()->assertJsonValidationErrors('plate_number');
    }

    /* ─────────────────── C. capacity_kg validation family, one per test ─────────────────── */

    public function test_vehicle_capacity_rejects_excess_decimal_places(): void
    {
        $this->probeCapacity('1.999');
    }

    public function test_vehicle_capacity_rejects_exponent_notation(): void
    {
        $this->probeCapacity('1e3');
    }

    public function test_vehicle_capacity_rejects_exponent_over_maximum(): void
    {
        $this->probeCapacity('1e17');
    }

    public function test_vehicle_capacity_rejects_large_exponent(): void
    {
        $this->probeCapacity('1e20');
    }

    public function test_vehicle_capacity_rejects_value_over_maximum(): void
    {
        $this->probeCapacity('100000000');
    }

    public function test_vehicle_capacity_rejects_negative_value(): void
    {
        $this->probeCapacity('-1');
    }

    public function test_vehicle_capacity_rejects_array_payload(): void
    {
        $this->probeCapacity(['a' => 1]);
    }

    public function test_vehicle_capacity_rejects_non_numeric_value(): void
    {
        $this->probeCapacity('abc');
    }

    private function probeCapacity(mixed $value): void
    {
        $admin = $this->systemAdmin();
        $plate = 'CP-'.substr(uniqid(), -8);
        $vehicleCount = Vehicle::query()->count();
        $r = $this->actingAs($admin)->postJson('/api/v1/supply-chain/vehicles', [
            'plate_number' => $plate,
            'name' => 'Capacity probe',
            'vehicle_type' => 'truck',
            'capacity_kg' => $value,
        ]);
        $r->assertUnprocessable()->assertJsonValidationErrors('capacity_kg');
        $this->assertSame($vehicleCount, Vehicle::query()->count());
        $this->assertDatabaseMissing('vehicles', ['plate_number' => $plate]);
    }

    /* ─────────────────────────── D. permission reachability ─────────────────────────── */

    public function test_fleet_routes_enforce_seeded_role_permissions(): void
    {
        $slugs = Role::query()->orderBy('slug')->pluck('slug')->all();
        foreach ($slugs as $slug) {
            $u = User::factory()->create([
                'role_id' => Role::query()->where('slug', $slug)->value('id'), 'is_active' => true,
            ]);
            $v = $this->vehicle();
            $originalName = $v->name;
            $canManageFleet = in_array($slug, ['impex_officer', 'system_admin'], true);
            $expectedRead = $canManageFleet ? 200 : 403;
            $expectedCreate = $canManageFleet ? 201 : 403;
            $expectedDelete = $canManageFleet ? 204 : 403;
            $plate = 'PM-'.substr(uniqid(), -8);

            $this->actingAs($u)->getJson('/api/v1/supply-chain/vehicles/options')->assertStatus($expectedRead);
            $this->actingAs($u)->getJson('/api/v1/supply-chain/vehicles')->assertStatus($expectedRead);
            $this->actingAs($u)->postJson('/api/v1/supply-chain/vehicles', [
                'plate_number' => $plate, 'name' => 'Permission matrix vehicle', 'vehicle_type' => 'van',
            ])->assertStatus($expectedCreate);
            if ($canManageFleet) {
                $this->assertDatabaseHas('vehicles', ['plate_number' => $plate]);
            } else {
                $this->assertDatabaseMissing('vehicles', ['plate_number' => $plate]);
            }

            $this->actingAs($u)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}", ['name' => 'Renamed'])
                ->assertStatus($expectedRead);
            $this->assertSame($canManageFleet ? 'Renamed' : $originalName, $v->fresh()->name);

            $this->actingAs($u)->deleteJson("/api/v1/supply-chain/vehicles/{$v->hash_id}")
                ->assertStatus($expectedDelete);
            $afterDelete = Vehicle::withTrashed()->findOrFail($v->id);
            $this->assertSame($canManageFleet, $afterDelete->deleted_at !== null);

            $this->actingAs($u)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}/restore")
                ->assertStatus($canManageFleet ? 200 : 403);
            $this->assertDatabaseHas('vehicles', ['id' => $v->id, 'deleted_at' => null]);

            $this->actingAs($u)->getJson('/api/v1/supply-chain/deliveries/driver-options')
                ->assertStatus($expectedRead);
        }
    }

    public function test_fleet_permissions_are_granted_only_to_expected_roles(): void
    {
        $holders = [];
        foreach (Role::query()->orderBy('slug')->get() as $role) {
            $u = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
            $holders[$role->slug] = [
                'fleet.manage' => $u->hasPermission('supply_chain.fleet.manage'),
                'deliveries.create' => $u->hasPermission('supply_chain.deliveries.create'),
                'deliveries.confirm' => $u->hasPermission('supply_chain.deliveries.confirm'),
                'driver.access' => $u->hasPermission('supply_chain.driver.access'),
                'supply_chain.view' => $u->hasPermission('supply_chain.view'),
            ];
        }
        $expectedHolders = [
            'fleet.manage' => ['impex_officer', 'system_admin'],
            'deliveries.create' => ['impex_officer', 'system_admin'],
            'deliveries.confirm' => ['impex_officer', 'system_admin'],
            'driver.access' => ['driver', 'system_admin'],
            'supply_chain.view' => ['impex_officer', 'system_admin'],
        ];
        foreach ($expectedHolders as $permission => $expected) {
            $actual = array_keys(array_filter($holders, static fn (array $map): bool => $map[$permission]));
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, "Unexpected holders for {$permission}.");
        }
    }

    /* ─────────────────────────── E. immutability / raw sql ─────────────────────────── */

    public function test_assignment_history_cannot_be_rewritten_after_loading(): void
    {
        $op = $this->operator();
        $driver = $this->driver();
        $replacementDriver = $this->driver();
        $v = $this->vehicle();
        $replacementVehicle = $this->vehicle();
        $d = $this->delivery($op);
        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe immutability baseline',
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');

        $notesBefore = (string) $d->fresh()->notes;
        $assignmentAudit = AuditLog::query()->where('model_type', $d->getMorphClass())
            ->where('model_id', $d->id)->latest('id')->first();
        $this->assertNotNull($assignmentAudit);
        $this->assertSame($op->id, $assignmentAudit->user_id);
        $this->assertSame('updated', $assignmentAudit->action);
        $this->assertStringContainsString('probe immutability baseline', $notesBefore);

        $this->actingAs($driver)
            ->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'loading'])
            ->assertOk()->assertJsonPath('data.status', 'loading');
        $this->assertSame('loading', $d->fresh()->status->value);
        $loadingAudit = AuditLog::query()->where('model_type', $d->getMorphClass())
            ->where('model_id', $d->id)->latest('id')->first();
        $this->assertNotNull($loadingAudit);
        $this->assertSame($driver->id, $loadingAudit->user_id);
        $this->assertSame('loading', $loadingAudit->new_values['status']);
        $auditCount = DB::table('audit_logs')->where('model_type', $d->getMorphClass())
            ->where('model_id', $d->id)->count();

        $this->actingAs($op)
            ->patchJson("/api/v1/supply-chain/deliveries/{$d->hash_id}/assignment", [
                'driver_id' => $replacementDriver->hash_id,
                'vehicle_id' => $replacementVehicle->hash_id,
                'reason' => 'attempt to rewrite dispatched assignment',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only scheduled deliveries can be assigned or reassigned.');

        $this->assertSame('loading', $d->fresh()->status->value);
        $this->assertSame($driver->id, $d->fresh()->driver_id);
        $this->assertSame($v->id, $d->fresh()->vehicle_id);
        $this->assertSame($notesBefore, (string) $d->fresh()->notes);
        $this->assertSame($auditCount, DB::table('audit_logs')->where('model_type', $d->getMorphClass())
            ->where('model_id', $d->id)->count());
    }

    /* ─────────────────────────── F. hash-id / error body hygiene ─────────────────────────── */

    public function test_errors_and_vehicle_resources_do_not_expose_raw_ids(): void
    {
        $admin = $this->systemAdmin();
        $op = $this->operator();
        $driver = $this->driver();
        $other = $this->driver();
        $v = $this->vehicle();
        $d = $this->delivery($op, $driver, $v);

        $bad = $this->actingAs($admin)->postJson('/api/v1/supply-chain/vehicles', [
            'plate_number' => $v->plate_number, 'name' => 'Dup plate', 'vehicle_type' => 'van',
        ]);
        $notFound = $this->actingAs($admin)->patchJson('/api/v1/supply-chain/vehicles/zzzzzzzz', ['name' => 'x']);
        $foreign = $this->actingAs($other)->getJson("/api/v1/driver/deliveries/{$d->hash_id}");
        $list = $this->actingAs($admin)->getJson('/api/v1/supply-chain/vehicles');

        $bad->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        $bad->assertJsonMissingPath('data.id');
        $notFound->assertNotFound()->assertJsonMissingPath('data.id');
        $foreign->assertNotFound()->assertJsonPath('message', 'The requested resource was not found.');
        $list->assertOk();

        $ids = array_column($list->json('data'), 'id');
        $this->assertContains($v->hash_id, $ids);
        $this->assertFalse(in_array($v->id, $ids, true), 'Vehicle list must expose opaque hash IDs, not database IDs.');
        $list->assertJsonMissingPath('data.0.asset_id');
    }

    /* ─────────────────────────── G. asset / maintenance seam ─────────────────────────── */

    public function test_vehicle_asset_id_cannot_be_set_through_mass_assignment(): void
    {
        $admin = $this->systemAdmin();
        $asset = Asset::create([
            'asset_code' => 'AST-M45-'.substr(uniqid(), -8),
            'name' => 'M045 vehicle asset',
            'category' => AssetCategory::Vehicle->value,
            'acquisition_date' => '2026-01-01',
            'acquisition_cost' => '100.00',
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'status' => AssetStatus::Active->value,
        ]);
        $plate = 'AS-'.substr(uniqid(), -8);

        $created = $this->actingAs($admin)->postJson('/api/v1/supply-chain/vehicles', [
            'plate_number' => $plate,
            'name' => 'Asset seam probe',
            'vehicle_type' => 'truck',
            'asset_id' => $asset->id,
        ])->assertCreated()->assertJsonMissingPath('data.asset_id');

        $vehicle = Vehicle::query()->where('plate_number', $plate)->sole();
        $this->assertNull($vehicle->asset_id);
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
        $this->actingAs($admin)->getJson('/api/v1/supply-chain/vehicles?search='.urlencode($plate))
            ->assertOk()->assertJsonPath('data.0.asset', null);
    }

    /* ─────────────────────────── H. list filter / ordering hygiene ─────────────────────────── */

    public function test_vehicle_list_pagination_and_search_inputs_are_safe(): void
    {
        $admin = $this->systemAdmin();
        $vehicle = $this->vehicle();

        $this->actingAs($admin)->getJson('/api/v1/supply-chain/vehicles?status=garbage')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($admin)->getJson('/api/v1/supply-chain/vehicles?per_page=-1')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/v1/supply-chain/vehicles?per_page=0')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/v1/supply-chain/vehicles?per_page=99999')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 100);
        $this->actingAs($admin)->getJson('/api/v1/supply-chain/vehicles?search=%25')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $vehicle->hash_id);
        $this->actingAs($admin)->getJson('/api/v1/supply-chain/vehicles?search=_')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $vehicle->hash_id);
        $this->actingAs($admin)->getJson('/api/v1/supply-chain/vehicles?trashed=bogus')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $vehicle->hash_id);
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function operator(): User
    {
        $role = Role::create([
            'name' => 'M045 operator '.uniqid(),
            'slug' => 'm045_op_'.substr(uniqid(), -8),
            'description' => 'probe operator',
        ]);
        $ids = Permission::query()
            ->whereIn('slug', ['supply_chain.deliveries.create', 'supply_chain.view', 'supply_chain.deliveries.view'])
            ->pluck('id')->all();
        $role->permissions()->syncWithoutDetaching($ids);

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
            'plate_number' => 'M45-'.substr(uniqid(), -8),
            'name' => 'Probe vehicle '.uniqid(),
            'vehicle_type' => 'van',
            'capacity_kg' => '1000.00',
            'status' => $status,
        ]);
    }

    private function delivery(User $creator, ?User $driver = null, ?Vehicle $vehicle = null): Delivery
    {
        $customer = Customer::create(['name' => 'M045 customer '.uniqid(), 'is_active' => true]);
        $order = SalesOrder::create([
            'so_number' => 'SO-M45-'.substr(uniqid(), -6),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '100.00',
            'vat_amount' => '12.00',
            'total_amount' => '112.00',
            'status' => 'confirmed',
            'created_by' => $creator->id,
        ]);

        return Delivery::create([
            'delivery_number' => 'DLV-M45-'.substr(uniqid(), -6),
            'sales_order_id' => $order->id,
            'driver_id' => $driver?->id,
            'vehicle_id' => $vehicle?->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
            'created_by' => $creator->id,
        ]);
    }
}
