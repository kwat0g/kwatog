<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceReadinessService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MaintenanceReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_idle_machine_is_ready_and_read_does_not_claim_equipment(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle']);
        $result = $this->readiness($this->order($machine));
        $this->assertTrue($result['can_start']);
        $this->assertSame('idle', $result['equipment_status']);
        $this->assertNull($result['production_work_order']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('idle', $machine->fresh()->status->value);
    }

    public function test_work_order_detail_exposes_readiness_before_start(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $actor = User::factory()->withRole('maintenance_tech')->create();
        $order = $this->order(Machine::factory()->create(['status' => 'idle']));

        $this->actingAs($actor)->getJson('/api/v1/maintenance/work-orders/'.$order->hash_id)
            ->assertOk()
            ->assertJsonPath('data.readiness.can_start', true)
            ->assertJsonPath('data.readiness.equipment_status', 'idle');
    }

    public function test_current_production_owner_requires_human_release_and_scopes_link(): void
    {
        $production = WorkOrder::factory()->create(['status' => 'paused']);
        $machine = Machine::factory()->create(['status' => 'breakdown', 'current_work_order_id' => $production->id]);
        $order = $this->order($machine);
        $result = $this->readiness($order);
        $this->assertFalse($result['can_start']);
        $this->assertSame('production_ownership', $result['blockers'][0]['code']);
        $this->assertStringContainsString('Release resources', $result['blockers'][0]['required_action']);
        $this->assertSame($production->hash_id, $result['production_work_order']['id']);
        $this->assertSame($production->wo_number, $result['production_work_order']['wo_number']);
        $this->assertNull($result['production_work_order']['link']);
        foreach ([false, true] as $allowed) {
            $actor = \Mockery::mock(User::class)->makePartial();
            $actor->shouldReceive('hasPermission')->with('production.view')->andReturn(true);
            $actor->shouldReceive('hasPermission')->with('production.work_orders.view')->andReturn($allowed);
            $this->assertSame($allowed ? '/production/work-orders/'.$production->hash_id : null,
                app(MaintenanceReadinessService::class)->forOrder($order, $actor)['production_work_order']['link']);
        }
    }

    public function test_active_production_order_blocks_readiness_when_owner_fields_are_stale(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle', 'current_work_order_id' => null]);
        $production = WorkOrder::factory()->create(['machine_id' => $machine->id, 'status' => 'in_progress']);
        $result = $this->readiness($this->order($machine));

        $this->assertFalse($result['can_start']);
        $this->assertSame('production_ownership', $result['blockers'][0]['code']);
        $this->assertSame($production->hash_id, $result['production_work_order']['id']);
    }

    public function test_current_reservation_blocks_but_released_and_future_reservations_do_not(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle']);
        $production = WorkOrder::factory()->create();
        $slot = ProductionSchedule::create([
            'work_order_id' => $production->id, 'machine_id' => $machine->id, 'mold_id' => $this->mold()->id,
            'scheduled_start' => now()->subMinute(), 'scheduled_end' => now()->addHour(),
            'status' => 'confirmed', 'reserves_capacity' => true, 'priority_order' => 1,
        ]);
        $order = $this->order($machine);
        $result = $this->readiness($order);
        $this->assertFalse($result['can_start']);
        $this->assertSame('production_reservation', $result['blockers'][0]['code']);
        $this->assertSame($production->hash_id, $result['production_work_order']['id']);
        $slot->update(['status' => 'executed']);
        $this->assertSame('production_reservation', $this->readiness($order)['blockers'][0]['code']);
        $slot->update(['reserves_capacity' => false]);
        $this->assertTrue($this->readiness($order)['can_start']);
        $slot->update(['reserves_capacity' => true, 'scheduled_start' => now()->addMinute()]);
        $this->assertTrue($this->readiness($order)['can_start']);
        $slot->update(['scheduled_start' => now()->subMinute(), 'scheduled_end' => now()]);
        $this->assertTrue($this->readiness($order)['can_start']);
    }

    public function test_in_progress_sibling_blocks_same_target_only(): void
    {
        $machine = Machine::factory()->create(['status' => 'maintenance']);
        $order = $this->order($machine);
        $sibling = $this->order($machine, 'in_progress');
        $this->assertSame('maintenance_in_progress', $this->readiness($order)['blockers'][0]['code']);
        $this->assertFalse($this->readiness($order)['can_start']);
        $sibling->update(['status' => 'completed']);
        $this->assertTrue($this->readiness($order)['can_start']);
        $order->update(['status' => 'in_progress']);
        $this->assertSame([], $this->readiness($order)['blockers']);
        $this->assertFalse($this->readiness($order)['can_start']);
    }

    public function test_mold_ownership_and_reservation_use_mold_target(): void
    {
        $production = WorkOrder::factory()->create(['status' => 'paused']);
        $mold = $this->mold();
        $mold->update(['status' => 'in_use', 'current_work_order_id' => $production->id]);
        $order = $this->order($mold);
        $this->assertSame('production_ownership', $this->readiness($order)['blockers'][0]['code']);
        $mold->update(['status' => 'available', 'current_work_order_id' => null]);
        ProductionSchedule::create(['work_order_id' => $production->id, 'mold_id' => $mold->id, 'machine_id' => Machine::factory()->create()->id,
            'scheduled_start' => now()->subMinute(), 'scheduled_end' => now()->addHour(),
            'status' => 'pending', 'reserves_capacity' => true, 'priority_order' => 1]);
        $this->assertSame('production_reservation', $this->readiness($order)['blockers'][0]['code']);
    }

    public function test_paused_production_must_release_both_resources_before_maintenance_can_start(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle']);
        $mold = $this->mold();
        $production = WorkOrder::factory()->create([
            'status' => 'paused', 'machine_id' => $machine->id, 'mold_id' => $mold->id,
        ]);
        $machine->update(['status' => 'running', 'current_work_order_id' => $production->id]);
        $mold->update(['status' => 'in_use', 'current_work_order_id' => $production->id]);
        $reservation = ProductionSchedule::create([
            'work_order_id' => $production->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now()->subMinute(), 'scheduled_end' => now()->addHour(),
            'status' => 'confirmed', 'reserves_capacity' => true, 'priority_order' => 1,
        ]);
        $machineOrder = $this->order($machine);
        $moldOrder = $this->order($mold);

        $this->assertFalse($this->readiness($machineOrder)['can_start']);
        $this->assertFalse($this->readiness($moldOrder)['can_start']);

        app(WorkOrderService::class)->releaseResources($production, 'Release equipment for maintenance');

        $this->assertFalse($reservation->fresh()->reserves_capacity);
        $this->assertNull($machine->fresh()->current_work_order_id);
        $this->assertNull($mold->fresh()->current_work_order_id);
        $this->assertTrue($this->readiness($machineOrder)['can_start']);
        $this->assertTrue($this->readiness($moldOrder)['can_start']);
    }

    public function test_missing_target_and_terminal_order_cannot_start(): void
    {
        $machine = Machine::factory()->create();
        $order = $this->order($machine, 'completed');
        $this->assertFalse($this->readiness($order)['can_start']);
        $machine->delete();
        $this->assertSame('equipment_unavailable', $this->readiness($order)['blockers'][0]['code']);
    }

    private function mold(): Mold
    {
        return Mold::create(['mold_code' => 'READY-'.uniqid(), 'name' => 'Readiness mold',
            'product_id' => Product::factory()->create()->id, 'cavity_count' => 1,
            'cycle_time_seconds' => 10, 'output_rate_per_hour' => 300,
            'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000,
            'status' => 'available']);
    }

    private function readiness(MaintenanceWorkOrder $order): array
    {
        return app(MaintenanceReadinessService::class)->forOrder($order, null);
    }

    private function order(Machine|Mold $target, string $status = 'open'): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create([
            'mwo_number' => 'READY-'.uniqid(), 'maintainable_type' => $target instanceof Mold ? 'mold' : 'machine',
            'maintainable_id' => $target->id, 'type' => 'corrective', 'priority' => 'high',
            'description' => 'Readiness test', 'status' => $status, 'created_by' => User::factory()->create()->id,
        ]);
    }
}
