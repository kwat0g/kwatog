<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceManualTargetAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->actingAs(User::factory()->withRole('maintenance_tech')->create());
    }

    public function test_manual_picker_excludes_equipment_in_use_but_schedule_picker_keeps_it(): void
    {
        $free = Machine::factory()->create(['status' => 'idle']);
        $running = Machine::factory()->create(['status' => 'running']);
        $production = WorkOrder::factory()->create();
        $owned = Machine::factory()->create(['status' => 'idle', 'current_work_order_id' => $production->id]);
        $availableMold = $this->mold('available');
        $busyMold = $this->mold('in_use');

        $machines = $this->getJson('/api/v1/maintenance/target-options?type=machine&available_only=1')->assertOk();
        $this->assertContains($free->hash_id, array_column($machines->json('data'), 'id'));
        $this->assertNotContains($running->hash_id, array_column($machines->json('data'), 'id'));
        $this->assertNotContains($owned->hash_id, array_column($machines->json('data'), 'id'));

        $molds = $this->getJson('/api/v1/maintenance/target-options?type=mold&available_only=1')->assertOk();
        $this->assertContains($availableMold->hash_id, array_column($molds->json('data'), 'id'));
        $this->assertNotContains($busyMold->hash_id, array_column($molds->json('data'), 'id'));

        $this->actingAs(User::factory()->withRole('maintenance_supervisor')->create());
        $scheduled = $this->getJson('/api/v1/maintenance/schedules/target-options?type=machine')->assertOk();
        $this->assertContains($running->hash_id, array_column($scheduled->json('data'), 'id'));
    }

    public function test_manual_create_rejects_stale_busy_target_and_automation_can_record_breakdown(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle']);
        $this->getJson('/api/v1/maintenance/target-options?type=machine&available_only=1')
            ->assertOk()->assertJsonFragment(['id' => $machine->hash_id]);
        $machine->update(['status' => 'running']);

        $this->postJson('/api/v1/maintenance/work-orders', $this->payload($machine->hash_id))
            ->assertUnprocessable()->assertJsonValidationErrors('maintainable_id');

        $automated = app(MaintenanceWorkOrderService::class)->create([
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'type' => 'corrective', 'priority' => 'high', 'description' => '[Breakdown] Production machine stopped',
        ], User::factory()->withRole('maintenance_supervisor')->create());
        $this->assertSame($machine->id, $automated->maintainable_id);
    }

    public function test_manual_create_rejects_current_reservation_and_in_use_mold(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle']);
        $mold = $this->mold('available');
        $production = WorkOrder::factory()->create();
        $slot = ProductionSchedule::create([
            'work_order_id' => $production->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now()->subMinute(), 'scheduled_end' => now()->addHour(),
            'status' => 'confirmed', 'reserves_capacity' => true, 'priority_order' => 1,
        ]);
        $this->getJson('/api/v1/maintenance/target-options?type=machine&available_only=1')
            ->assertOk()->assertJsonMissing(['id' => $machine->hash_id]);
        $this->getJson('/api/v1/maintenance/target-options?type=mold&available_only=1')
            ->assertOk()->assertJsonMissing(['id' => $mold->hash_id]);
        $this->postJson('/api/v1/maintenance/work-orders', $this->payload($machine->hash_id))
            ->assertUnprocessable()->assertJsonValidationErrors('maintainable_id');
        $this->postJson('/api/v1/maintenance/work-orders', $this->payload($mold->hash_id, 'mold'))
            ->assertUnprocessable()->assertJsonValidationErrors('maintainable_id');

        $slot->update(['reserves_capacity' => false]);
        $mold->update(['status' => 'in_use']);
        $this->postJson('/api/v1/maintenance/work-orders', $this->payload($mold->hash_id, 'mold'))
            ->assertUnprocessable()->assertJsonValidationErrors('maintainable_id');
        $this->postJson('/api/v1/maintenance/work-orders', $this->payload($machine->hash_id))
            ->assertCreated();
    }

    public function test_forged_create_fields_and_numeric_ids_cannot_bypass_busy_target_guard(): void
    {
        $machine = Machine::factory()->create(['status' => 'running']);
        $mold = $this->mold('in_use');

        foreach ([[$machine, 'machine'], [$mold, 'mold']] as [$target, $type]) {
            $this->postJson('/api/v1/maintenance/work-orders', array_merge(
                $this->payload((string) $target->id, $type),
                ['status' => 'in_progress', 'manual' => false, 'verification_required' => false, 'schedule_id' => 1],
            ))->assertUnprocessable()->assertJsonValidationErrors('maintainable_id');
        }

        $this->assertSame(0, MaintenanceWorkOrder::count());
    }

    public function test_active_production_order_blocks_machine_selection_even_when_owner_fields_are_stale(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle', 'current_work_order_id' => null]);
        WorkOrder::factory()->create(['status' => 'in_progress', 'machine_id' => $machine->id]);

        $this->getJson('/api/v1/maintenance/target-options?type=machine&available_only=1')
            ->assertOk()->assertJsonMissing(['id' => $machine->hash_id]);
        $this->postJson('/api/v1/maintenance/work-orders', $this->payload($machine->hash_id))
            ->assertUnprocessable()->assertJsonValidationErrors('maintainable_id');
        $this->assertSame(0, MaintenanceWorkOrder::count());
    }

    public function test_active_production_order_blocks_mold_selection_even_when_owner_fields_are_stale(): void
    {
        $mold = $this->mold('available');
        WorkOrder::factory()->create([
            'status' => 'in_progress', 'mold_id' => $mold->id, 'product_id' => $mold->product_id,
        ]);

        $this->getJson('/api/v1/maintenance/target-options?type=mold&available_only=1')
            ->assertOk()->assertJsonMissing(['id' => $mold->hash_id]);
        $this->postJson('/api/v1/maintenance/work-orders', $this->payload($mold->hash_id, 'mold'))
            ->assertUnprocessable()->assertJsonValidationErrors('maintainable_id');
        $this->assertSame(0, MaintenanceWorkOrder::count());
    }

    private function mold(string $status): Mold
    {
        return Mold::create([
            'mold_code' => 'SELECT-'.uniqid(), 'name' => 'Target test mold',
            'product_id' => Product::factory()->create()->id, 'cavity_count' => 1,
            'cycle_time_seconds' => 10, 'output_rate_per_hour' => 300,
            'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000,
            'status' => $status,
        ]);
    }

    private function payload(string $id, string $type = 'machine'): array
    {
        return [
            'maintainable_type' => $type, 'maintainable_id' => $id,
            'type' => 'corrective', 'priority' => 'high', 'description' => 'Inspect equipment',
        ];
    }
}
