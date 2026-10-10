<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Common\Services\SettingsService;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
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
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaintenanceOperationalSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Carbon::setTestNow('2026-10-04 12:00:00');
        $this->actor = User::factory()->create(['is_active' => true]);
        $department = Department::firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance']);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        $role = Role::firstOrCreate(['slug' => 'maintenance_tech'], ['name' => 'Maintenance Technician']);
        $this->technician = User::factory()->create([
            'role_id' => $role->id, 'employee_id' => $employee->id, 'is_active' => true,
        ]);
        $shift = Shift::create([
            'name' => 'Operational safety shift', 'start_time' => '00:00', 'end_time' => '23:59', 'is_active' => true,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'shift_id' => $shift->id,
            'effective_date' => '2026-01-01', 'working_days' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        Attendance::create([
            'employee_id' => $employee->id, 'shift_id' => $shift->id,
            'date' => now()->toDateString(), 'status' => 'present', 'time_in' => now()->subHour(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_mold_start_claims_mold_until_completion(): void
    {
        $mold = $this->mold();
        $mwo = $this->mwo($mold);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($mwo, $this->actor);
        $this->assertSame('maintenance', $mold->fresh()->status->value);
        $this->recordPassed($mwo);
        $service->complete($mwo, [], $this->actor);
        $this->assertSame('available', $mold->fresh()->status->value);
        $this->assertSame(0, $mold->fresh()->current_shot_count);
    }

    public function test_mold_start_rejects_active_production_ownership(): void
    {
        $mold = $this->mold('in_use');
        WorkOrder::factory()->create(['mold_id' => $mold->id, 'product_id' => $mold->product_id, 'status' => 'in_progress']);
        $mwo = $this->mwo($mold);
        try {
            app(MaintenanceWorkOrderService::class)->start($mwo, $this->actor);
            $this->fail('Maintenance must not take a production-owned mold.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('maintainable_id', $e->errors());
        }
        $this->assertSame('open', $mwo->fresh()->status->value);
        $this->assertSame('in_use', $mold->fresh()->status->value);
    }

    public function test_mold_start_rejects_active_production_order_with_stale_owner_fields(): void
    {
        $mold = $this->mold('available');
        WorkOrder::factory()->create([
            'mold_id' => $mold->id, 'product_id' => $mold->product_id, 'status' => 'in_progress',
        ]);
        $mwo = $this->mwo($mold);

        try {
            app(MaintenanceWorkOrderService::class)->start($mwo, $this->actor);
            $this->fail('Active production must block a mold with stale owner fields.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('maintainable_id', $error->errors());
        }
        $this->assertSame('open', $mwo->fresh()->status->value);
        $this->assertSame('available', $mold->fresh()->status->value);
    }

    public function test_legacy_mold_completion_cannot_reset_active_production_shots(): void
    {
        $mold = $this->mold('in_use');
        WorkOrder::factory()->create(['mold_id' => $mold->id, 'product_id' => $mold->product_id, 'status' => 'in_progress']);
        $mwo = $this->mwo($mold, 'in_progress');
        $this->recordPassed($mwo);
        try {
            app(MaintenanceWorkOrderService::class)->complete($mwo, [], $this->actor);
            $this->fail('Maintenance completion must preserve shots while production owns the mold.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('maintainable_id', $e->errors());
        }
        $this->assertSame(5000, $mold->fresh()->current_shot_count);
        $this->assertSame('in_progress', $mwo->fresh()->status->value);
    }

    public function test_cancelled_mold_maintenance_releases_without_resetting_shots(): void
    {
        $mold = $this->mold();
        $mwo = $this->mwo($mold);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($mwo, $this->actor);
        $service->cancel($mwo, 'Duplicate repair', $this->actor);
        $this->assertSame('available', $mold->fresh()->status->value);
        $this->assertSame(5000, $mold->fresh()->current_shot_count);
    }

    public function test_cancellation_does_not_release_shot_limit_mold(): void
    {
        $mold = $this->mold('maintenance');
        $mold->update(['current_shot_count' => 100000]);
        $mwo = $this->mwo($mold);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($mwo, $this->actor);
        $service->cancel($mwo, 'Defer repair', $this->actor);
        $this->assertSame('maintenance', $mold->fresh()->status->value);
        $this->assertSame(100000, $mold->fresh()->current_shot_count);
    }

    public function test_second_machine_maintenance_start_is_rejected(): void
    {
        $machine = Machine::factory()->create();
        $first = $this->mwo($machine);
        $second = $this->mwo($machine);
        $second->update(['assigned_to' => $this->makeAvailableTechnician()->employee_id]);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($first, $this->actor);
        try {
            $service->start($second, $this->actor);
            $this->fail('One machine cannot have two active maintenance owners.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('maintainable_id', $e->errors());
        }
        $this->assertSame('open', $second->fresh()->status->value);
        $this->assertSame('maintenance', $machine->fresh()->status->value);
    }

    public function test_machine_start_rejects_active_production_order_with_stale_owner_fields(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle', 'current_work_order_id' => null]);
        WorkOrder::factory()->create(['machine_id' => $machine->id, 'status' => 'in_progress']);
        $mwo = $this->mwo($machine);

        try {
            app(MaintenanceWorkOrderService::class)->start($mwo, $this->actor);
            $this->fail('Active production must block a machine with stale owner fields.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('maintainable_id', $error->errors());
        }
        $this->assertSame('open', $mwo->fresh()->status->value);
        $this->assertSame('idle', $machine->fresh()->status->value);
    }

    public function test_cancelling_unstarted_duplicate_preserves_active_machine_maintenance(): void
    {
        $machine = Machine::factory()->create();
        $active = $this->mwo($machine);
        $duplicate = $this->mwo($machine);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($active, $this->actor);
        $service->cancel($duplicate, 'Duplicate', $this->actor);
        $this->assertSame('maintenance', $machine->fresh()->status->value);
        $this->assertNull(MachineDowntime::where('maintenance_order_id', $active->id)->firstOrFail()->end_time);
    }

    public function test_legacy_sibling_completion_keeps_machine_unavailable(): void
    {
        $machine = Machine::factory()->create();
        $first = $this->mwo($machine);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($first, $this->actor);
        $sibling = $this->mwo($machine, 'in_progress');
        $this->recordPassed($first);
        $service->complete($first, [], $this->actor);
        $this->assertSame('maintenance', $machine->fresh()->status->value);
        $this->assertSame('in_progress', $sibling->fresh()->status->value);
    }

    public function test_repaired_paused_work_order_preserves_pre_breakdown_runtime(): void
    {
        $machine = Machine::factory()->create(['status' => 'breakdown']);
        $production = WorkOrder::factory()->create([
            'machine_id' => $machine->id, 'status' => 'paused',
            'actual_start' => now()->subHours(3), 'actual_end' => null,
        ]);
        $mwo = $this->mwo($machine);
        MachineDowntime::create([
            'machine_id' => $machine->id, 'work_order_id' => $production->id,
            'maintenance_order_id' => $mwo->id,
            'start_time' => now()->subHour(), 'category' => 'breakdown',
        ]);
        app(MachineHoursService::class)->recompute();
        $this->assertSame('2.00', $machine->fresh()->running_hours_total);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($mwo, $this->actor);
        $this->recordPassed($mwo);
        $service->complete($mwo, [], $this->actor);
        $this->travel(2)->hours();
        app(MachineHoursService::class)->recompute();
        $this->assertSame('2.00', $machine->fresh()->running_hours_total);
        $this->assertSame('paused', $production->fresh()->status->value);
    }

    public function test_hourly_completion_baseline_includes_all_pre_service_runtime(): void
    {
        $machine = Machine::factory()->create(['running_hours_total' => '0.00']);
        WorkOrder::factory()->create([
            'machine_id' => $machine->id, 'status' => 'completed',
            'actual_start' => now()->subHours(3), 'actual_end' => now()->subHour(),
        ]);
        $schedule = MaintenanceSchedule::create([
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'schedule_type' => 'preventive', 'description' => 'Hourly service',
            'interval_type' => 'hours', 'interval_value' => 1,
            'running_hours_baseline' => '0.00', 'is_active' => true,
        ]);
        $mwo = $this->mwo($machine, 'open', $schedule);
        $service = app(MaintenanceWorkOrderService::class);
        $service->start($mwo, $this->actor);
        $this->recordPassed($mwo);
        $service->complete($mwo, [], $this->actor);
        $this->assertSame('2.00', $schedule->fresh()->running_hours_baseline);
        app(MachineHoursService::class)->recompute();
        $due = app(MaintenanceScheduleService::class)->machineHourSchedulesAtOrAboveThreshold();
        $this->assertFalse($due->contains('id', $schedule->id));
    }

    public function test_fractional_spare_part_usage_matches_stock_ledger_precision(): void
    {
        [$mwo, $item, $location, $level] = $this->sparePartFixture();
        $usage = app(SparePartUsageService::class)->record($mwo, [
            'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => '0.001',
            'work_record_id' => $mwo->logs()->whereNotNull('finding')->value('id'),
        ], $this->actor)->fresh();
        $this->assertSame('9.999', $level->fresh()->quantity);
        $this->assertSame('0.001', $usage->quantity);
        $this->assertSame('10.0051', $usage->unit_cost);
        $this->assertSame('0.01', $usage->total_cost);
        $this->assertSame('0.01', $mwo->fresh()->cost);
    }

    public function test_spare_part_issue_rejects_quantity_below_stock_precision(): void
    {
        [$mwo, $item, $location, $level] = $this->sparePartFixture();
        $this->expectException(ValidationException::class);
        app(SparePartUsageService::class)->record($mwo, [
            'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => '0.0011',
            'work_record_id' => $mwo->logs()->whereNotNull('finding')->value('id'),
        ], $this->actor);
    }

    public function test_spare_part_issue_rejects_fractional_pieces(): void
    {
        [$mwo, $item, $location] = $this->sparePartFixture();
        $item->update(['unit_of_measure' => 'PCS']);
        $this->expectException(ValidationException::class);
        app(SparePartUsageService::class)->record($mwo, [
            'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => '0.5',
            'work_record_id' => $mwo->logs()->whereNotNull('finding')->value('id'),
        ], $this->actor);
    }

    public function test_spare_part_issue_spans_lots_and_return_reverses_stock_and_cost(): void
    {
        [$mwo, $item, $location, $level] = $this->sparePartFixture();
        $level->forceFill(['quantity' => '0.000'])->save();
        foreach (['LOT-A', 'LOT-B'] as $lot) {
            app(StockMovementService::class)->move(new StockMovementInput(
                type: StockMovementType::AdjustmentIn, itemId: $item->id,
                toLocationId: $location->id, quantity: '1.000', unitCost: '10.00',
                lotNumber: $lot, createdBy: $this->actor->id,
            ));
        }
        $service = app(SparePartUsageService::class);
        $input = [
            'item_id' => $item->id, 'location_id' => $location->id,
            'quantity' => '2.000', 'operation_key' => '7810bc65-9811-4636-94fa-df0dd16ce34d',
            'work_record_id' => $mwo->logs()->whereNotNull('finding')->value('id'),
        ];
        $service->record($mwo, $input, $this->actor);
        $this->assertSame(2, $mwo->spareParts()->count());
        $this->assertSame('0.000', $level->fresh()->quantity);
        $this->assertSame('20.00', $mwo->fresh()->cost);

        $service->record($mwo, $input, $this->actor);
        $this->assertSame(2, $mwo->spareParts()->count());
        $usage = $mwo->spareParts()->orderBy('id')->firstOrFail();
        $returnKey = '95939835-6803-4e13-9955-fe88b24d2e5f';
        $service->returnPart($mwo, $usage, '1.000', $this->actor, $returnKey);
        $this->assertSame('1.000', $level->fresh()->quantity);
        $this->assertSame('10.00', $mwo->fresh()->cost);
        $this->assertSame('1.000', $usage->fresh()->returned_quantity);
        $service->returnPart($mwo, $usage, '1.000', $this->actor, $returnKey);
        $this->assertSame('1.000', $usage->fresh()->returned_quantity);

        try {
            $service->returnPart($mwo, $usage, '1.000', $this->actor, '0890a013-9e9a-4967-b470-eae0699d4682');
            $this->fail('The part was returned twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
        // Direct issues enter the warehouse handover queue without stock moving
        // twice; repair continues only after physical receipt is confirmed.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $warehouse = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'), 'is_active' => true,
        ]);
        $movement = StockMovement::query()->where('reference_type', 'maintenance_work_order')
            ->where('reference_id', $mwo->id)->where('lot_number', 'LOT-B')->firstOrFail();
        $this->actingAs($warehouse)->postJson("/api/v1/inventory/stock-movements/{$movement->hash_id}/handover", [
            'received_by_employee_id' => $this->technician->employee->hash_id,
            'expected_quantity' => '1.000',
        ])->assertOk();
        $this->recordPassed($mwo);
        app(MaintenanceWorkOrderService::class)->reconcileParts($mwo, ['confirm_consumed' => true], $this->technician);
        app(MaintenanceWorkOrderService::class)->complete($mwo, [], $this->actor);
        $this->assertSame('10.00', $mwo->fresh()->cost);
    }

    public function test_concurrent_starts_claim_machine_once(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'));
        $machine = Machine::factory()->create(['status' => 'idle']);
        $orders = [$this->mwo($machine), $this->mwo($machine)];
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
        DB::beginTransaction();
        Machine::whereKey($machine->id)->lockForUpdate()->firstOrFail();
        $workers = [];
        foreach ($orders as $index => $order) {
            [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($parent);
                config(['database.connections.start_race' => config('database.connections.pgsql'), 'database.default' => 'start_race']);
                DB::purge('start_race');
                DB::selectOne('SELECT set_config(?, ?, false)', ['application_name', 'maintenance_start_race_'.$index]);
                try {
                    app(MaintenanceWorkOrderService::class)->start(
                        MaintenanceWorkOrder::findOrFail($order->id), User::findOrFail($this->actor->id),
                    );
                    fwrite($child, 'started');
                } catch (ValidationException $error) {
                    fwrite($child, 'rejected');
                } catch (\Throwable $error) {
                    fwrite($child, $error->getMessage());
                }
                fclose($child);
                exit(0);
            }
            $this->assertGreaterThan(0, $pid);
            fclose($child);
            stream_set_timeout($parent, 15);
            $workers[] = [$pid, $parent];
        }
        $deadline = microtime(true) + 10;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $blocked = DB::table('pg_stat_activity')->whereIn('application_name', [
                'maintenance_start_race_0', 'maintenance_start_race_1',
            ])->where('wait_event_type', 'Lock')->count();
            if ($blocked === 2) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        DB::commit();
        $outcomes = [];
        foreach ($workers as [$pid, $socket]) {
            $outcomes[] = stream_get_contents($socket);
            fclose($socket);
            pcntl_waitpid($pid, $status);
        }
        sort($outcomes);
        $this->assertSame(2, $blocked, json_encode($outcomes));
        $this->assertSame(['rejected', 'started'], $outcomes);
        $this->assertSame(1, MaintenanceWorkOrder::whereIn('id', array_map(fn ($order) => $order->id, $orders))
            ->where('status', 'in_progress')->count());
        $this->assertSame('maintenance', $machine->fresh()->status->value);
    }

    private function recordPassed(MaintenanceWorkOrder $order): void
    {
        app(MaintenanceWorkOrderService::class)->recordWork($order, [
            'finding' => 'Equipment inspected',
            'action_taken' => 'Completed the required repair or service',
            'result' => 'passed',
        ], $this->actor);
    }

    private function sparePartFixture(): array
    {
        app(SettingsService::class)->set('modules.accounting', false);
        $mwo = $this->mwo(Machine::factory()->create(), 'in_progress');
        $item = Item::factory()->create(['item_type' => 'spare_part', 'unit_of_measure' => 'kg']);
        $location = WarehouseLocation::factory()->create();
        $level = StockLevel::create([
            'item_id' => $item->id, 'location_id' => $location->id,
            'quantity' => '10.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '10.0051',
        ]);
        $this->recordPassed($mwo);

        return [$mwo, $item, $location, $level];
    }

    private function mold(string $status = 'available'): Mold
    {
        return Mold::create([
            'mold_code' => 'SAFETY-'.uniqid(), 'name' => 'Safety mold',
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
            'mwo_number' => 'SAFETY-'.uniqid(),
            'maintainable_type' => $target instanceof Mold ? 'mold' : 'machine',
            'maintainable_id' => $target->id, 'schedule_id' => $schedule?->id,
            'type' => 'corrective', 'priority' => 'high',
            'description' => 'Safety service', 'status' => $status,
            'assigned_to' => $this->technician->employee_id,
            'created_by' => $this->actor->id,
        ]);
    }

    private function makeAvailableTechnician(): User
    {
        $department = Department::firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance']);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        $role = Role::firstOrCreate(['slug' => 'maintenance_tech'], ['name' => 'Maintenance Technician']);
        $technician = User::factory()->create([
            'role_id' => $role->id, 'employee_id' => $employee->id, 'is_active' => true,
        ]);
        $shift = Shift::create([
            'name' => 'Second operational safety shift', 'start_time' => '00:00', 'end_time' => '23:59', 'is_active' => true,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'shift_id' => $shift->id,
            'effective_date' => '2026-01-01', 'working_days' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        Attendance::create([
            'employee_id' => $employee->id, 'shift_id' => $shift->id,
            'date' => now()->toDateString(), 'status' => 'present', 'time_in' => now()->subHour(),
        ]);

        return $technician;
    }
}
