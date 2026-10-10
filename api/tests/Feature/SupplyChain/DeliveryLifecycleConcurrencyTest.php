<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryStockReservation;
use App\Modules\SupplyChain\Models\DeliveryStockReservationBatch;
use App\Modules\SupplyChain\Models\Vehicle;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\PreparesDeliveryShipment;
use Tests\Support\ReceivesProductionOutput;
use Tests\TestCase;

/**
 * Proof and deletion operations must use the authoritative delivery row at
 * the point where they mutate state, not a stale route-bound instance.
 */
class DeliveryLifecycleConcurrencyTest extends TestCase
{
    use ReceivesProductionOutput;
    use PreparesDeliveryShipment;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->orderBy('id')->value('id'),
        ]);
    }

    private function makeDelivery(User $user, string $status): Delivery
    {
        // Source stock comes through production; callers performing a real
        // departure reserve it first. Status is an explicit starting state for
        // stale-row checks that do not exercise a transition.
        [$delivery] = $this->dispatchableDelivery($user, '3.00');
        $delivery->forceFill([
            'status' => $status,
            'scheduled_date' => now()->toDateString(),
        ])->save();

        return $delivery->fresh();
    }

    public function test_stale_delete_cannot_delete_a_delivery_that_is_now_confirmed(): void
    {
        $user = $this->makeUser();
        $delivery = $this->makeDelivery($user, 'scheduled');
        $stale = $delivery->fresh();

        $delivery->forceFill(['status' => 'confirmed'])->save();

        $this->expectException(BusinessRuleException::class);
        app(DeliveryService::class)->delete($stale);

        $this->assertNotNull(Delivery::withTrashed()->find($delivery->id));
    }

    public function test_stale_receipt_upload_cannot_add_proof_to_a_delivery_that_is_now_cancelled(): void
    {
        $user = User::factory()->withRole('driver')->create(['is_active' => true]);
        $delivery = $this->makeDelivery($user, 'delivered');
        $delivery->forceFill(['driver_id' => $user->id])->save();
        $stale = $delivery->fresh();
        $delivery->forceFill(['status' => 'cancelled'])->save();

        try {
            app(DeliveryService::class)->uploadReceiptPhoto(
                $stale,
                UploadedFile::fake()->image('receipt.jpg'),
                $user,
            );
            $this->fail('A stale receipt upload should be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(
                'Customer receipt evidence can only be uploaded after dispatch.',
                $e->getMessage(),
            );
        }

        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_vehicle_cannot_be_activated_for_two_deliveries_at_once(): void
    {
        $user = $this->makeUser();
        $vehicle = Vehicle::create([
            'plate_number' => 'CONC-'.substr(uniqid(), -6),
            'name' => 'Concurrency truck',
            'vehicle_type' => 'truck',
            'capacity_kg' => '1000.00',
            'status' => 'available',
        ]);
        $first = $this->makeDelivery($user, DeliveryStatus::Scheduled->value);
        $second = $this->makeDelivery($user, DeliveryStatus::Scheduled->value);
        $first->forceFill(['vehicle_id' => $vehicle->id])->save();
        $second->forceFill(['vehicle_id' => $vehicle->id])->save();
        $warehouse = User::factory()->withRole('warehouse_staff')->create(['is_active' => true]);
        $this->reserveDeliveryStock($first, $warehouse);
        $this->reserveDeliveryStock($second, $warehouse);

        $service = app(DeliveryService::class);
        $service->updateStatus($first->fresh(), DeliveryStatus::Loading);

        try {
            $service->updateStatus($second->fresh(), DeliveryStatus::Loading);
            $this->fail('A vehicle already carrying an active delivery must not be assigned again.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already assigned to another active delivery', $e->getMessage());
        }

        $this->assertSame(DeliveryStatus::Loading, $first->fresh()->status);
        $this->assertSame(DeliveryStatus::Scheduled, $second->fresh()->status);
        $this->assertSame('in_use', $vehicle->fresh()->status);
    }

    public function test_driver_cannot_enter_two_active_deliveries_at_once(): void
    {
        $user = $this->makeUser();
        $driver = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'driver')->value('id'),
            'is_active' => true,
        ]);
        $firstVehicle = Vehicle::create([
            'plate_number' => 'DRV-'.substr(uniqid(), -6),
            'name' => 'First driver concurrency truck',
            'vehicle_type' => 'truck',
            'capacity_kg' => '1000.00',
            'status' => 'available',
        ]);
        $secondVehicle = Vehicle::create([
            'plate_number' => 'DRV-'.substr(uniqid(), -6),
            'name' => 'Second driver concurrency truck',
            'vehicle_type' => 'truck',
            'capacity_kg' => '1000.00',
            'status' => 'available',
        ]);
        $first = $this->makeDelivery($user, DeliveryStatus::Scheduled->value);
        $second = $this->makeDelivery($user, DeliveryStatus::Scheduled->value);
        $first->forceFill(['driver_id' => $driver->id, 'vehicle_id' => $firstVehicle->id])->save();
        $second->forceFill(['driver_id' => $driver->id, 'vehicle_id' => $secondVehicle->id])->save();
        $warehouse = User::factory()->withRole('warehouse_staff')->create(['is_active' => true]);
        $this->reserveDeliveryStock($first, $warehouse);
        $this->reserveDeliveryStock($second, $warehouse);

        $service = app(DeliveryService::class);
        $service->updateStatus($first->fresh(), DeliveryStatus::Loading);
        $this->prepareShipmentForDispatch($first);
        $service->updateStatus($first->fresh(), DeliveryStatus::InTransit);

        try {
            $service->updateStatus($second->fresh(), DeliveryStatus::Loading);
            $this->fail('A driver already on an active delivery must not be double-booked.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already assigned to another active delivery', $e->getMessage());
        }

        $this->assertSame(DeliveryStatus::InTransit, $first->fresh()->status);
        $this->assertSame(DeliveryStatus::Scheduled, $second->fresh()->status);
    }

    public function test_delivery_cannot_depart_if_finished_goods_gl_handoff_fails_after_reservation(): void
    {
        $user = $this->makeUser();
        app(SettingsService::class)->set('modules.accounting', false, 'modules');
        [$delivery, $item, $location] = $this->dispatchableDelivery($user, '3.00');
        $line = $delivery->items()->firstOrFail();
        $inspection = Inspection::query()->findOrFail($line->inspection_id);
        $receipt = StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $inspection->work_order_output_id)
            ->firstOrFail();

        $this->assertSame(MovementGlHandoffStatus::NotRequired, $receipt->gl_handoff_status);

        $warehouse = User::factory()->withRole('warehouse_staff')->create(['is_active' => true]);
        $this->reserveDeliveryStock($delivery, $warehouse);
        $this->assertSame(DeliveryStatus::Scheduled, $delivery->fresh()->status);

        $operator = User::factory()->withRole('impex_officer')->create(['is_active' => true]);
        $driver = User::factory()->withRole('driver')->create(['is_active' => true]);
        $vehicle = Vehicle::create([
            'plate_number' => 'GL-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'GL gate delivery truck',
            'vehicle_type' => 'truck',
            'capacity_kg' => '1000.00',
            'status' => 'available',
        ]);
        $this->assertTrue($operator->hasPermission('supply_chain.deliveries.create'));
        $this->actingAs($operator)
            ->patchJson('/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/assignment', [
                'driver_id' => $driver->hash_id,
                'vehicle_id' => $vehicle->hash_id,
                'reason' => 'Assign operator and vehicle before loading.',
            ])
            ->assertOk();
        $this->actingAs($operator)
            ->patchJson('/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/status', ['status' => 'loading'])
            ->assertOk()
            ->assertJsonPath('data.status', 'loading');

        $this->prepareShipmentForDispatch($delivery->fresh());
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');
        $settings->set('accounting.accounts.material_consumption_code', '999999', 'accounting');
        $finance = User::factory()->withRole('finance_officer')->create(['is_active' => true]);
        $this->assertTrue($finance->hasPermission('accounting.journal.post'));
        $this->actingAs($finance)
            ->postJson('/api/v1/inventory/stock-movements/'.$receipt->hash_id.'/retry-gl')
            ->assertOk()
            ->assertJsonPath('data.gl_handoff.status', 'manual_required');
        $this->assertSame(MovementGlHandoffStatus::ManualRequired, $receipt->fresh()->gl_handoff_status);

        $level = StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $movementCount = StockMovement::query()->count();
        $batch = DeliveryStockReservationBatch::query()->where('delivery_id', $delivery->id)->firstOrFail();
        $reservation = DeliveryStockReservation::query()->where('delivery_id', $delivery->id)->firstOrFail();
        $this->assertSame('reserved', $batch->status->value);
        $this->assertSame('3.000', (string) $level->reserved_quantity);
        $this->assertSame('3.000', (string) $reservation->quantity);
        $auditCount = AuditLog::query()
            ->where('model_type', $delivery->getMorphClass())
            ->where('model_id', $delivery->id)
            ->count();
        $quantity = (string) $level->quantity;
        $reservedQuantity = (string) $level->reserved_quantity;

        $response = $this->actingAs($operator)
            ->patchJson('/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/status', ['status' => 'in_transit']);
        $response->assertUnprocessable();
        $this->assertStringContainsString('General Ledger', (string) $response->json('message'));

        $this->assertSame(DeliveryStatus::Loading, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->departed_at);
        $this->assertSame($quantity, (string) $level->fresh()->quantity);
        $this->assertSame($reservedQuantity, (string) $level->fresh()->reserved_quantity);
        $this->assertSame($batch->status, $batch->fresh()->status);
        $this->assertSame((string) $reservation->quantity, (string) $reservation->fresh()->quantity);
        $this->assertSame((string) $reservation->consumed_quantity, (string) $reservation->fresh()->consumed_quantity);
        $this->assertSame($reservation->status, $reservation->fresh()->status);
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($auditCount, AuditLog::query()
            ->where('model_type', $delivery->getMorphClass())
            ->where('model_id', $delivery->id)
            ->count());
        $this->assertSame(MovementGlHandoffStatus::ManualRequired, $receipt->fresh()->gl_handoff_status);
    }
}
