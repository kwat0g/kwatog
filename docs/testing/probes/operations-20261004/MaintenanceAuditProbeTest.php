<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MachineHoursService;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\Maintenance\Services\SparePartUsageService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Temporary audit probes: assertions document observed defects, not desired behavior. */
class MaintenanceAuditProbeTest extends TestCase
{
    use DatabaseTransactions;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.pgsql.database') !== 'ogami_test_pricing_20261004') {
            throw new \RuntimeException('Audit probes require the isolated test database.');
        }
        config(['cache.default' => 'array', 'queue.default' => 'sync']);
        Event::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->actor = User::factory()->create([
            'is_active' => true,
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mold(string $status = 'available'): Mold
    {
        return Mold::create([
            'mold_code' => 'AUDIT-'.uniqid(), 'name' => 'Audit mold',
            'product_id' => Product::factory()->create()->id,
            'cavity_count' => 1, 'cycle_time_seconds' => 10,
            'output_rate_per_hour' => 300, 'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000, 'current_shot_count' => 5000,
            'status' => $status,
        ]);
    }

    private function mwo(Machine|Mold $target, string $status = 'open', ?MaintenanceSchedule $schedule = null): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create([
            'mwo_number' => 'AUDIT-'.uniqid(),
            'maintainable_type' => $target instanceof Mold ? 'mold' : 'machine',
            'maintainable_id' => $target->id, 'schedule_id' => $schedule?->id,
            'type' => 'corrective', 'priority' => 'high',
            'description' => 'Audit service', 'status' => $status,
            'created_by' => $this->actor->id,
        ]);
    }

    public function test_probe_mold_start_leaves_it_schedulable(): void
    {
        $mold = $this->mold();
        $machine = Machine::factory()->create();
        $mold->compatibleMachines()->attach($machine->id);
        $mwo = $this->mwo($mold);
        app(MaintenanceWorkOrderService::class)->start($mwo, $this->actor);
        $this->assertSame('available', $mold->fresh()->status->value);
        $production = WorkOrder::factory()->create(['product_id' => $mold->product_id, 'quantity_target' => 1]);
        app(WorkOrderService::class)->assertAssignmentValid($production, $machine, $mold->fresh());
        $this->assertSame('in_progress', $mwo->fresh()->status->value);
    }

    public function test_probe_mold_start_accepts_production_owned_mold_and_resets_shots(): void
    {
        $mold = $this->mold('in_use');
        $machine = Machine::factory()->create();
        $production = WorkOrder::factory()->create([
            'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'product_id' => $mold->product_id, 'status' => 'in_progress',
        ]);
        $machine->update(['status' => 'running', 'current_work_order_id' => $production->id]);
        $mwo = $this->mwo($mold);
        app(MaintenanceWorkOrderService::class)->start($mwo, $this->actor);
        app(MaintenanceWorkOrderService::class)->complete($mwo, [], $this->actor);
        $this->assertSame('in_use', $mold->fresh()->status->value);
        $this->assertSame(0, $mold->fresh()->current_shot_count);
        $this->assertSame('in_progress', $production->fresh()->status->value);
    }

    public function test_probe_second_machine_mwo_starts_and_first_completion_releases_machine(): void
    {
        $machine = Machine::factory()->create();
        $first = $this->mwo($machine);
        $second = $this->mwo($machine);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($first, $this->actor);
        $service->start($second, $this->actor);
        $this->assertSame('in_progress', $second->fresh()->status->value);
        $this->assertSame(0, MachineDowntime::where('maintenance_order_id', $second->id)->count());
        $service->complete($first, [], $this->actor);
        $this->assertSame('idle', $machine->fresh()->status->value);
        $this->assertSame('in_progress', $second->fresh()->status->value);
    }

    public function test_probe_cancel_open_mwo_releases_machine_owned_by_another_mwo(): void
    {
        $machine = Machine::factory()->create();
        $active = $this->mwo($machine);
        $unstarted = $this->mwo($machine);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($active, $this->actor);
        $service->cancel($unstarted, 'Duplicate', $this->actor);
        $this->assertSame('idle', $machine->fresh()->status->value);
        $this->assertSame('in_progress', $active->fresh()->status->value);
        $this->assertNull(MachineDowntime::where('maintenance_order_id', $active->id)->firstOrFail()->end_time);
    }

    public function test_probe_tech_cannot_reach_required_lookup_routes(): void
    {
        $tech = User::factory()->create([
            'is_active' => true, 'role_id' => Role::where('slug', 'maintenance_tech')->value('id'),
        ]);
        $this->assertTrue($tech->hasPermission('maintenance.wo.complete'));
        $this->assertFalse($tech->hasPermission('inventory.view'));
        $this->assertFalse($tech->hasPermission('mrp.molds.view'));
        $this->actingAs($tech)->getJson('/api/v1/inventory/items?item_type=spare_part')->assertForbidden();
        $this->actingAs($tech)->getJson('/api/v1/inventory/stock-levels')->assertForbidden();
        $this->actingAs($tech)->getJson('/api/v1/mrp/molds')->assertForbidden();
    }

    public function test_probe_usage_quantity_rounds_away_real_stock_consumption(): void
    {
        app(SettingsService::class)->set('modules.accounting', false);
        $machine = Machine::factory()->create();
        $mwo = $this->mwo($machine, 'in_progress');
        $item = Item::factory()->create(['item_type' => 'spare_part']);
        $location = WarehouseLocation::factory()->create();
        $level = StockLevel::create([
            'item_id' => $item->id, 'location_id' => $location->id,
            'quantity' => '10.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '10.0000',
        ]);
        $usage = app(SparePartUsageService::class)->record($mwo, [
            'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => '0.001',
        ], $this->actor);
        $this->assertSame('9.999', $level->fresh()->quantity);
        $this->assertSame('0.00', $usage->fresh()->quantity);
        $this->assertSame('0.001', $usage->stockMovement->quantity);
    }

    public function test_probe_completion_uses_stale_hours_and_pre_service_runtime_triggers_repeat(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        $machine = Machine::factory()->create(['running_hours_total' => '0.00']);
        WorkOrder::factory()->create([
            'machine_id' => $machine->id, 'status' => 'completed',
            'actual_start' => now()->subHours(3), 'actual_end' => now()->subHour(),
        ]);
        $schedule = MaintenanceSchedule::create([
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'schedule_type' => 'preventive', 'description' => 'Hourly audit',
            'interval_type' => 'hours', 'interval_value' => 1,
            'running_hours_baseline' => '0.00', 'is_active' => true,
        ]);
        $mwo = $this->mwo($machine, 'open', $schedule);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($mwo, $this->actor);
        $service->complete($mwo, [], $this->actor);
        $this->assertSame('0.00', $schedule->fresh()->running_hours_baseline);
        app(MachineHoursService::class)->recompute();
        $this->assertSame('2.00', $machine->fresh()->running_hours_total);
        $due = app(MaintenanceScheduleService::class)->machineHourSchedulesAtOrAboveThreshold();
        $this->assertTrue($due->contains('id', $schedule->id));
    }

    public function test_safeguard_description_only_update_preserves_completion_due_date(): void
    {
        $machine = Machine::factory()->create();
        $schedule = MaintenanceSchedule::create([
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'schedule_type' => 'preventive', 'description' => 'Before',
            'interval_type' => 'days', 'interval_value' => 30,
            'last_performed_at' => '2026-09-01 00:00:00', 'next_due_at' => '2026-10-01 00:00:00', 'is_active' => true,
        ]);
        $stale = MaintenanceSchedule::findOrFail($schedule->id);
        $service = app(MaintenanceScheduleService::class);
        $service->recomputeNextDueAt($schedule, Carbon::parse('2026-10-04 00:00:00'));
        $this->assertSame('2026-11-03', $schedule->fresh()->next_due_at->toDateString());
        $service->update($stale, ['description' => 'After']);
        $this->assertSame('2026-11-03', $schedule->fresh()->next_due_at->toDateString());
        $this->assertSame('2026-10-04', $schedule->fresh()->last_performed_at->toDateString());
    }

    public function test_probe_repair_completion_erases_runtime_of_paused_work_order(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        $machine = Machine::factory()->create(['status' => 'breakdown']);
        $production = WorkOrder::factory()->create([
            'machine_id' => $machine->id, 'status' => 'paused',
            'actual_start' => now()->subHours(3), 'actual_end' => null,
        ]);
        $mwo = $this->mwo($machine, 'in_progress');
        $downtime = MachineDowntime::create([
            'machine_id' => $machine->id, 'work_order_id' => $production->id,
            'maintenance_order_id' => $mwo->id,
            'start_time' => now()->subHour(), 'category' => 'breakdown',
        ]);
        app(MachineHoursService::class)->recompute();
        $this->assertSame('2.00', $machine->fresh()->running_hours_total);
        app(MaintenanceWorkOrderService::class)->complete($mwo, [], $this->actor);
        $this->assertNotNull($downtime->fresh()->end_time);
        app(MachineHoursService::class)->recompute();
        $this->assertSame('0.00', $machine->fresh()->running_hours_total);
        $this->assertSame('paused', $production->fresh()->status->value);
    }
}
