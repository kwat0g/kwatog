<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Models\MaintenanceWindow;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use App\Modules\Maintenance\Services\MaintenanceWindowService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\ProductionResourceService;
use App\Modules\Production\Services\WorkOrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MaintenanceWindowConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private function order(Machine|Mold $target, User $actor): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create(['mwo_number' => 'WINDOW-'.uniqid(),
            'maintainable_type' => $target instanceof Machine ? 'machine' : 'mold', 'maintainable_id' => $target->id,
            'type' => 'preventive', 'priority' => 'medium', 'description' => 'Scheduled inspection',
            'status' => 'open', 'created_by' => $actor->id]);
    }

    private function resources(): array
    {
        $actor = User::factory()->withRole('maintenance_supervisor')->create();
        $wo = WorkOrder::factory()->create(['status' => 'planned', 'quantity_target' => 50, 'planned_start' => now()->addHour(), 'planned_end' => now()->addDays(3)]);
        $machine = Machine::factory()->create(['status' => 'idle', 'available_hours_per_day' => 8]);
        $mold = Mold::create(['product_id' => $wo->product_id, 'mold_code' => 'WINDOW-'.uniqid(), 'name' => 'Window mold',
            'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'setup_time_minutes' => 10,
            'current_shot_count' => 0, 'max_shots_before_maintenance' => 1000, 'lifetime_max_shots' => 10000, 'status' => 'available']);
        $mold->compatibleMachines()->sync([$machine->id]);

        return [$actor, $wo, $machine, $mold];
    }

    public function test_production_booking_blocks_maintenance_confirmation(): void
    {
        [$actor, $wo, $machine, $mold] = $this->resources();
        ProductionSchedule::create(['work_order_id' => $wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(2), 'priority_order' => 0,
            'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true]);
        $this->expectException(ValidationException::class);
        app(MaintenanceWindowService::class)->confirm($this->order($machine, $actor), now()->addHour(), now()->addHours(2), $actor);
    }

    public function test_confirmed_machine_and_mold_windows_block_production_and_adjacent_slots_remain_valid(): void
    {
        [$actor, $wo, $machine, $mold] = $this->resources();
        foreach ([$machine, $mold] as $target) {
            $order = $this->order($target, $actor);
            app(MaintenanceWindowService::class)->confirm($order, now()->addHour(), now()->addHours(2), $actor);
            try {
                app(WorkOrderService::class)->confirm($wo, $machine->id, $mold->id);
                $this->fail('Maintenance must block production.');
            } catch (BusinessRuleException $error) {
                $this->assertStringContainsString('preventive maintenance', $error->getMessage());
            }
            app(ProductionResourceService::class)->assertReservation($wo, $machine, $mold, now()->addHours(2), now()->addHours(3));
            app(MaintenanceWindowService::class)->release($order);
        }
    }

    public function test_proposed_and_confirmed_windows_do_not_stop_machine(): void
    {
        [$actor, , $machine] = $this->resources();
        $order = $this->order($machine, $actor);
        MaintenanceWindow::create(['maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'work_order_id' => $order->id, 'planned_start_at' => now()->addHour(), 'planned_end_at' => now()->addHours(2)]);
        app(MaintenanceWindowService::class)->confirm($order, now()->addHour(), now()->addHours(2), $actor);
        $this->assertSame('idle', $machine->fresh()->status->value);
        $this->assertNull($machine->fresh()->current_work_order_id);
        $this->assertNull($order->fresh()->started_at);
        $start = now()->addHours(3)->startOfMinute();
        $window = app(MaintenanceWindowService::class)->confirm($order, $start->copy()->utc(), $start->copy()->addHour()->utc(), $actor);
        $this->assertSame($start->toDateTimeString(), $window->fresh()->planned_start_at->toDateTimeString());
    }

    public function test_cancellation_and_completion_release_reservation(): void
    {
        [$actor, , $machine] = $this->resources();
        foreach (['cancel', 'complete'] as $action) {
            $order = $this->order($machine, $actor);
            $window = app(MaintenanceWindowService::class)->confirm($order, now()->addHour(), now()->addHours(2), $actor);
            if ($action === 'cancel') {
                app(MaintenanceWorkOrderService::class)->reconcileParts($order, [
                    'inspection_reason' => 'No technician assigned to inspect this order.',
                ], $actor);
                app(MaintenanceWorkOrderService::class)->cancel($order, 'No longer needed', $actor);
            } else {
                $order->update(['status' => 'in_progress', 'started_at' => now()]);
                app(MaintenanceWorkOrderService::class)->recordWork($order, [
                    'finding' => 'Scheduled inspection complete',
                    'action_taken' => 'Checked equipment condition',
                    'result' => 'passed',
                ], $actor);
                app(MaintenanceWorkOrderService::class)->complete($order, [], $actor);
            }
            $this->assertSame('released', $window->fresh()->state);
        }
    }

    public function test_start_before_confirmed_window_is_rejected_without_changing_equipment(): void
    {
        [$actor, , $machine] = $this->resources();
        $order = $this->order($machine, $actor);
        $department = Department::firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance']);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        $role = Role::firstOrCreate(['slug' => 'maintenance_tech'], ['name' => 'Maintenance Technician']);
        User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id, 'is_active' => true]);
        $shift = Shift::create([
            'name' => 'Window conflict shift', 'start_time' => '00:00', 'end_time' => '23:59', 'is_active' => true,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'shift_id' => $shift->id,
            'effective_date' => now()->toDateString(), 'working_days' => [now()->dayOfWeekIso],
        ]);
        Attendance::create([
            'employee_id' => $employee->id, 'shift_id' => $shift->id,
            'date' => now()->toDateString(), 'status' => 'present', 'time_in' => now()->subHour(),
        ]);
        $order->update(['assigned_to' => $employee->id]);
        app(MaintenanceWindowService::class)->confirm($order, now()->addHour(), now()->addHours(2), $actor);
        try {
            app(MaintenanceWorkOrderService::class)->start($order, $actor);
            $this->fail('The confirmed interval must be reviewed again before starting early.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('planned_start_at', $error->errors());
        }
        $this->assertSame('open', $order->fresh()->status->value);
        $this->assertSame('idle', $machine->fresh()->status->value);
    }

    public function test_window_duration_bounds_and_schedule_default(): void
    {
        [$actor, , $machine] = $this->resources();
        $schedule = app(MaintenanceScheduleService::class)->create([
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->id, 'description' => 'Inspection',
            'interval_type' => 'days', 'interval_value' => 7,
        ]);
        $this->assertSame(60, $schedule->fresh()->estimated_duration_minutes);
        $schedules = app(MaintenanceScheduleService::class);
        $this->assertSame(1, $schedules->list(['maintainable_type' => 'machine', 'maintainable_id' => $machine->hash_id])->total());
        $this->assertSame(0, $schedules->list(['maintainable_type' => 'machine', 'maintainable_id' => 'invalid-hash'])->total());
        $due = $schedule->next_due_at->toDateTimeString();
        app(MaintenanceScheduleService::class)->update($schedule, ['estimated_duration_minutes' => 90]);
        $this->assertSame($due, $schedule->fresh()->next_due_at->toDateTimeString());
        foreach ([14, 481] as $minutes) {
            try {
                app(MaintenanceWindowService::class)->confirm($this->order($machine, $actor), now()->addHour(), now()->addHour()->addMinutes($minutes), $actor);
                $this->fail('Out-of-range duration must be rejected.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('planned_start_at', $error->errors());
            }
        }
    }

    public function test_database_exclusion_rejects_overlapping_confirmed_windows(): void
    {
        [$actor, , $machine] = $this->resources();
        $first = $this->order($machine, $actor);
        $second = $this->order($machine, $actor);
        $window = app(MaintenanceWindowService::class)->confirm($first, now()->addHour(), now()->addHours(2), $actor);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('maintenance_windows_no_overlap');
        DB::transaction(fn () => MaintenanceWindow::create(array_replace($window->only([
            'maintainable_type', 'maintainable_id', 'planned_start_at', 'planned_end_at', 'state', 'confirmed_by', 'confirmed_at',
        ]), ['work_order_id' => $second->id])));
    }

    public function test_non_supervisor_cannot_confirm(): void
    {
        [$actor, , $machine] = $this->resources();
        $technician = User::factory()->withRole('maintenance_tech')->create();
        $this->expectException(HttpException::class);
        app(MaintenanceWindowService::class)->confirm($this->order($machine, $actor), now()->addHour(), now()->addHours(2), $technician);
    }

    public function test_concurrent_confirmations_wait_for_same_equipment_and_only_one_wins(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'));
        [$actor, , $machine] = $this->resources();
        $orders = [$this->order($machine, $actor), $this->order($machine, $actor)];
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
                config(['database.connections.window_race' => config('database.connections.pgsql'), 'database.default' => 'window_race']);
                DB::purge('window_race');
                DB::selectOne('SELECT set_config(?, ?, false)', ['application_name', 'maintenance_window_race_'.$index]);
                try {
                    app(MaintenanceWindowService::class)->confirm(MaintenanceWorkOrder::findOrFail($order->id), now()->addHour(), now()->addHours(2), User::findOrFail($actor->id));
                    fwrite($child, 'confirmed');
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
            $blocked = DB::table('pg_stat_activity')->whereIn('application_name', ['maintenance_window_race_0', 'maintenance_window_race_1'])
                ->where('wait_event_type', 'Lock')->count();
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
        $this->assertSame(['confirmed', 'rejected'], $outcomes);
        $this->assertSame(1, MaintenanceWindow::confirmed()->where('maintainable_id', $machine->id)->count());
    }
}
