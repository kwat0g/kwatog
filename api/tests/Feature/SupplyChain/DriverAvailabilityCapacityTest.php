<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DriverCredential;
use App\Modules\SupplyChain\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\HasDeliveryStock;
use Tests\TestCase;

class DriverAvailabilityCapacityTest extends TestCase
{
    use HasDeliveryStock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_dispatch_plan_rejects_weight_above_locked_vehicle_capacity(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        $vehicle->update(['capacity_kg' => '500.00']);

        $this->actingAs($operator)->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle, '500.01'))
            ->assertUnprocessable()->assertJsonValidationErrors('shipment_weight_kg');

        $this->assertNull($delivery->fresh()->driver_id);
        $this->assertNull($delivery->fresh()->shipment_weight_kg);
    }

    public function test_dispatch_plan_persists_weight_when_it_fits_vehicle_capacity(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        $vehicle->update(['capacity_kg' => '500.00']);

        $this->actingAs($operator)->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle, '499.99'))
            ->assertOk()->assertJsonPath('data.shipment_weight_kg', '499.99')
            ->assertJsonPath('data.vehicle.capacity_kg', 500);

        $this->assertSame('499.99', $delivery->fresh()->shipment_weight_kg);
    }

    public function test_dispatch_plan_rejects_an_expired_driver_credential(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        $vehicle->update(['capacity_kg' => '500.00']);
        $manager = User::factory()->withRole('system_admin')->create(['is_active' => true]);
        $this->actingAs($manager)->putJson("/api/v1/supply-chain/drivers/{$driver->hash_id}/credential", [
            'license_number' => 'DL-EXPIRED',
            'expires_at' => today()->subDay()->toDateString(),
        ])->assertOk();

        $this->actingAs($operator)->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle, '100.00'))
            ->assertUnprocessable()->assertJsonValidationErrors('driver_id');

        $this->assertNull($delivery->fresh()->driver_id);
    }

    public function test_dispatch_plan_rejects_a_driver_without_a_license_credential(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        DriverCredential::query()->where('user_id', $driver->id)->delete();

        $this->actingAs($operator)->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle, '100.00'))
            ->assertUnprocessable()->assertJsonValidationErrors('driver_id');

        $this->assertNull($delivery->fresh()->driver_id);
    }

    public function test_dispatch_plan_rejects_an_inactive_vehicle(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        $vehicle->update(['status' => 'maintenance']);

        $this->actingAs($operator)->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle, '100.00'))
            ->assertUnprocessable();

        $this->assertNull($delivery->fresh()->vehicle_id);
    }

    public function test_dispatch_plan_rejects_a_driver_with_another_scheduled_delivery_on_that_date(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        $otherVehicle = Vehicle::create([
            'plate_number' => 'OVR-'.substr(uniqid(), -8), 'name' => 'Other run van',
            'vehicle_type' => 'van', 'capacity_kg' => '1000.00', 'status' => 'available',
        ]);
        [$otherDelivery] = $this->deliveryWithApprovedStock($operator);
        $otherDelivery->forceFill(['scheduled_date' => today()->toDateString()])->save();

        $this->actingAs($operator)->patchJson("/api/v1/supply-chain/deliveries/{$otherDelivery->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $otherVehicle->hash_id, 'reason' => 'Reserve driver for first run.',
            'shipment_weight_kg' => '100.00',
        ])->assertOk();

        $this->actingAs($operator)->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle, '100.00'))
            ->assertUnprocessable();

        $this->assertNull($delivery->fresh()->driver_id);
    }

    public function test_dispatch_assignment_requires_a_pin_for_an_existing_delivery_address(): void
    {
        $operator = User::factory()->withRole('impex_officer')->create(['is_active' => true]);
        $driver = User::factory()->withRole('driver')->create(['is_active' => true]);
        $this->addCurrentCredential($driver);
        $vehicle = Vehicle::create([
            'plate_number' => 'PIN-'.substr(uniqid(), -8), 'name' => 'Pin check van',
            'vehicle_type' => 'van', 'capacity_kg' => '1000.00', 'status' => 'available',
        ]);
        $customer = Customer::factory()->create([
            'delivery_address' => '123 Unmapped Road, Quezon City',
            'delivery_latitude' => null,
            'delivery_longitude' => null,
        ]);
        [$delivery] = $this->deliveryWithApprovedStock($operator, $customer);

        $this->actingAs($operator)->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle, '100.00'))
            ->assertUnprocessable()->assertJsonValidationErrors('delivery_location');

        $this->assertNull($delivery->fresh()->driver_id);
    }

    public function test_fleet_manager_can_store_and_download_a_private_driver_credential(): void
    {
        $manager = User::factory()->withRole('system_admin')->create(['is_active' => true]);
        $driver = User::factory()->withRole('driver')->create(['is_active' => true]);
        $file = UploadedFile::fake()->createWithContent('license.pdf', "%PDF-1.4\nlicense evidence\n%%EOF");

        $response = $this->actingAs($manager)->putJson("/api/v1/supply-chain/drivers/{$driver->hash_id}/credential", [
            'license_number' => 'DL-2026-001',
            'expires_at' => now()->addYear()->toDateString(),
            'credential_document' => $file,
        ])->assertOk()->assertJsonPath('data.license_number', 'DL-2026-001')
            ->assertJsonPath('data.status', 'valid');

        $credential = DriverCredential::query()->where('user_id', $driver->id)->firstOrFail();
        Storage::disk('local')->assertExists($credential->document_path);
        $this->assertArrayNotHasKey('document_path', $response->json('data'));

        $this->actingAs($manager)->get("/api/v1/supply-chain/driver-credentials/{$credential->hash_id}/document")
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_driver_credential_document_requires_fleet_permission(): void
    {
        $driver = User::factory()->withRole('driver')->create(['is_active' => true]);
        $employee = User::factory()->withRole('employee')->create(['is_active' => true]);

        $this->actingAs($employee)->putJson("/api/v1/supply-chain/drivers/{$driver->hash_id}/credential", [
            'license_number' => 'DL-SECRET', 'expires_at' => now()->addYear()->toDateString(),
        ])->assertForbidden();
    }

    public function test_driver_credential_upload_rejects_oversized_documents(): void
    {
        $manager = User::factory()->withRole('system_admin')->create(['is_active' => true]);
        $driver = User::factory()->withRole('driver')->create(['is_active' => true]);

        $this->actingAs($manager)->putJson("/api/v1/supply-chain/drivers/{$driver->hash_id}/credential", [
            'license_number' => 'DL-TOO-LARGE',
            'expires_at' => now()->addYear()->toDateString(),
            'credential_document' => UploadedFile::fake()->create('oversized.pdf', 10241, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('credential_document');
    }

    private function shipment(): array
    {
        $operator = User::factory()->withRole('impex_officer')->create(['is_active' => true]);
        $driver = User::factory()->withRole('driver')->create(['is_active' => true]);
        $this->addCurrentCredential($driver);
        $vehicle = Vehicle::create([
            'plate_number' => 'CAP-'.substr(uniqid(), -8), 'name' => 'Capacity test van',
            'vehicle_type' => 'van', 'capacity_kg' => '1000.00', 'status' => 'available',
        ]);
        [$delivery] = $this->deliveryWithApprovedStock($operator);
        $delivery->forceFill(['scheduled_date' => today()->toDateString()])->save();

        return [$delivery->fresh(), $operator, $driver, $vehicle];
    }

    private function addCurrentCredential(User $driver): void
    {
        DriverCredential::query()->create([
            'user_id' => $driver->id,
            'license_number' => 'DL-'.substr(uniqid(), -8),
            'expires_at' => today()->addYear()->toDateString(),
        ]);
    }

    private function plan(User $driver, Vehicle $vehicle, string $weight): array
    {
        return [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $vehicle->hash_id,
            'shipment_weight_kg' => $weight,
            'scheduled_date' => today()->toDateString(),
            'expected_delivery_date' => today()->addDay()->toDateString(),
            'carrier' => 'Capacity test carrier', 'reason' => 'Set shipment and carrier details.',
            'dispatch' => false,
        ];
    }

    private function planUrl(Delivery $delivery): string
    {
        return "/api/v1/supply-chain/deliveries/{$delivery->hash_id}/shipment-plan";
    }
}
