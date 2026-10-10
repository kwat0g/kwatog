<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Common\Enums\PermissionOverrideType;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWindow;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MaintenanceWindowSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-09 20:00', config('app.timezone')));
    }

    private function technician(string $start = '22:00', string $end = '06:00'): Employee
    {
        $department = Department::firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance']);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        User::factory()->withRole('maintenance_tech')->create(['employee_id' => $employee->id, 'is_active' => true]);
        $shift = Shift::create(['name' => 'Window shift '.uniqid(), 'start_time' => $start, 'end_time' => $end, 'is_active' => true]);
        EmployeeShiftAssignment::create(['employee_id' => $employee->id, 'shift_id' => $shift->id,
            'effective_date' => '2026-01-01', 'working_days' => [1, 2, 3, 4, 5, 6, 7]]);

        return $employee;
    }

    private function schedule(Machine|Mold $target, User $actor, int $duration = 60): array
    {
        $schedule = app(MaintenanceScheduleService::class)->create([
            'maintainable_type' => $target instanceof Machine ? 'machine' : 'mold',
            'maintainable_id' => $target->id, 'description' => 'Window inspection',
            'interval_type' => 'days', 'interval_value' => 7, 'estimated_duration_minutes' => $duration,
        ]);
        $order = MaintenanceWorkOrder::create(['mwo_number' => 'WINDOW-SUGGEST-'.uniqid(),
            'maintainable_type' => $target instanceof Machine ? 'machine' : 'mold',
            'maintainable_id' => $target->id, 'schedule_id' => $schedule->id,
            'type' => 'preventive', 'priority' => 'medium', 'description' => 'Window inspection',
            'status' => 'open', 'created_by' => $actor->id, 'verification_required' => true]);

        return [$schedule, $order];
    }

    private function production(Machine|Mold $target, Carbon $start, Carbon $end): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => 'planned', 'quantity_target' => 50,
            'planned_start' => $start, 'planned_end' => $end->copy()->addDay()]);
        $machine = $target instanceof Machine ? $target : Machine::factory()->create(['status' => 'idle']);
        $mold = $target instanceof Mold ? $target : $this->mold($workOrder);
        ProductionSchedule::create(['work_order_id' => $workOrder->id,
            'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => $start, 'scheduled_end' => $end, 'priority_order' => 0,
            'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true]);
    }

    private function mold(WorkOrder $workOrder): Mold
    {
        return Mold::create(['product_id' => $workOrder->product_id, 'mold_code' => 'WINDOW-'.uniqid(),
            'name' => 'Window mold', 'cavity_count' => 1, 'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100, 'setup_time_minutes' => 10,
            'current_shot_count' => 0, 'max_shots_before_maintenance' => 1000,
            'lifetime_max_shots' => 10000, 'status' => 'available']);
    }

    public function test_night_shift_suggestion_confirms_and_assigns_without_stopping_machine(): void
    {
        $actor = User::factory()->withRole('maintenance_supervisor')->create();
        $technician = $this->technician();
        $machine = Machine::factory()->create(['status' => 'idle']);
        [$schedule, $order] = $this->schedule($machine, $actor, 90);

        $response = $this->actingAs($actor)->getJson('/api/v1/maintenance/schedules/'.$schedule->hash_id.'/window-suggestions')
            ->assertOk()->assertJsonPath('data.work_order_id', $order->hash_id)
            ->assertJsonPath('data.suggestions.0.technician.id', $technician->hash_id);
        $slot = $response->json('data.suggestions.0');
        $this->assertSame('2026-10-09 22:00:00', Carbon::parse($slot['planned_start_at'])->timezone(config('app.timezone'))->toDateTimeString());

        $this->actingAs($actor)->postJson('/api/v1/maintenance/work-orders/'.$order->hash_id.'/window', [
            'planned_start_at' => $slot['planned_start_at'], 'planned_end_at' => $slot['planned_end_at'],
            'employee_id' => $technician->hash_id,
        ])->assertCreated()->assertJsonPath('data.technician.id', $technician->hash_id);
        $this->assertSame('assigned', $order->fresh()->status->value);
        $this->assertSame($technician->id, $order->fresh()->assigned_to);
        $this->assertSame('idle', $machine->fresh()->status->value);
        $this->assertDatabaseHas('maintenance_windows', ['work_order_id' => $order->id, 'state' => 'confirmed']);
    }

    public function test_stale_production_booking_rejects_confirmation_and_preserves_unassigned_order(): void
    {
        $actor = User::factory()->withRole('maintenance_supervisor')->create();
        $technician = $this->technician();
        [$schedule, $order] = $this->schedule(Machine::factory()->create(['status' => 'idle']), $actor);
        $slot = $this->actingAs($actor)->getJson('/api/v1/maintenance/schedules/'.$schedule->hash_id.'/window-suggestions')
            ->assertOk()->json('data.suggestions.0');
        $this->production($schedule->maintainable(), Carbon::parse($slot['planned_start_at']), Carbon::parse($slot['planned_end_at']));

        $this->actingAs($actor)->postJson('/api/v1/maintenance/work-orders/'.$order->hash_id.'/window', [
            'planned_start_at' => $slot['planned_start_at'], 'planned_end_at' => $slot['planned_end_at'],
            'employee_id' => $technician->hash_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('planned_start_at');
        $this->assertNull($order->fresh()->assigned_to);
        $this->assertSame(0, MaintenanceWindow::confirmed()->where('work_order_id', $order->id)->count());
    }

    public function test_stale_leave_rejects_confirmation_and_rolls_back_the_window(): void
    {
        $actor = User::factory()->withRole('maintenance_supervisor')->create();
        $technician = $this->technician();
        [$schedule, $order] = $this->schedule(Machine::factory()->create(['status' => 'idle']), $actor);
        $slot = $this->actingAs($actor)->getJson('/api/v1/maintenance/schedules/'.$schedule->hash_id.'/window-suggestions')
            ->assertOk()->json('data.suggestions.0');
        $date = Carbon::parse($slot['planned_start_at'])->timezone(config('app.timezone'))->toDateString();
        $leave = LeaveRequest::factory()->create(['employee_id' => $technician->id,
            'start_date' => $date, 'end_date' => $date]);
        $leave->forceFill(['status' => 'approved'])->save();

        $this->actingAs($actor)->postJson('/api/v1/maintenance/work-orders/'.$order->hash_id.'/window', [
            'planned_start_at' => $slot['planned_start_at'], 'planned_end_at' => $slot['planned_end_at'],
            'employee_id' => $technician->hash_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->assertNull($order->fresh()->assigned_to);
        $this->assertSame(0, MaintenanceWindow::confirmed()->where('work_order_id', $order->id)->count());
    }

    public function test_window_confirmation_requires_assignment_permission(): void
    {
        $actor = User::factory()->withRole('maintenance_supervisor')->create();
        $technician = $this->technician();
        [$schedule, $order] = $this->schedule(Machine::factory()->create(['status' => 'idle']), $actor);
        $slot = $this->actingAs($actor)->getJson('/api/v1/maintenance/schedules/'.$schedule->hash_id.'/window-suggestions')
            ->assertOk()->json('data.suggestions.0');
        UserPermissionOverride::create([
            'user_id' => $actor->id,
            'permission_id' => Permission::where('slug', 'maintenance.wo.assign')->firstOrFail()->id,
            'type' => PermissionOverrideType::Revoke,
            'granted_by' => $actor->id,
            'reason' => 'Assignment permission revoked for test',
        ]);
        $actor->flushPermissionsCache();

        $this->actingAs($actor)->postJson('/api/v1/maintenance/work-orders/'.$order->hash_id.'/window', [
            'planned_start_at' => $slot['planned_start_at'], 'planned_end_at' => $slot['planned_end_at'],
            'employee_id' => $technician->hash_id,
        ])->assertForbidden();
        $this->assertSame(0, MaintenanceWindow::confirmed()->where('work_order_id', $order->id)->count());
    }

    public function test_mold_reservation_and_leave_move_slot_to_next_available_night(): void
    {
        $actor = User::factory()->withRole('maintenance_supervisor')->create();
        $technician = $this->technician();
        $mold = $this->mold(WorkOrder::factory()->create());
        [$schedule] = $this->schedule($mold, $actor);
        $this->production($mold, Carbon::parse('2026-10-09 22:00', config('app.timezone')),
            Carbon::parse('2026-10-10 06:00', config('app.timezone')));
        $leave = LeaveRequest::factory()->create(['employee_id' => $technician->id,
            'start_date' => '2026-10-10', 'end_date' => '2026-10-10']);
        $leave->forceFill(['status' => 'approved'])->save();

        $response = $this->actingAs($actor)->getJson('/api/v1/maintenance/schedules/'.$schedule->hash_id.'/window-suggestions')
            ->assertOk();
        $this->assertSame('2026-10-11 22:00:00', Carbon::parse($response->json('data.suggestions.0.planned_start_at'))
            ->timezone(config('app.timezone'))->toDateTimeString());
    }

    public function test_no_eligible_interval_reports_staffing_and_capacity_reasons(): void
    {
        $actor = User::factory()->withRole('maintenance_supervisor')->create();
        [$schedule] = $this->schedule(Machine::factory()->create(['status' => 'idle']), $actor);
        $this->production($schedule->maintainable(), now()->addMinutes(15), now()->addDay());

        $response = $this->actingAs($actor)->getJson('/api/v1/maintenance/schedules/'.$schedule->hash_id.'/window-suggestions')
            ->assertOk()->assertJsonCount(0, 'data.suggestions');
        $this->assertNotEmpty($response->json('data.blockers.production'));
        $this->assertNotEmpty($response->json('data.blockers.staffing'));
    }

    public function test_duplicate_generation_preserves_single_open_preventive_order(): void
    {
        $actor = User::factory()->withRole('maintenance_supervisor')->create();
        [$schedule, $order] = $this->schedule(Machine::factory()->create(['status' => 'idle']), $actor);
        $schedule->forceFill(['next_due_at' => now()->subMinute()])->save();
        $generated = app(MaintenanceWorkOrderService::class)->create([], $actor, $schedule);

        $this->assertSame($order->id, $generated->id);
        $this->assertSame(1, MaintenanceWorkOrder::where('schedule_id', $schedule->id)->count());
    }
}
