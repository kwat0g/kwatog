<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\MaintenanceWindow;
use App\Modules\Maintenance\Services\MaintenanceAssigneeSuggestionService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\MRP\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaintenanceAssigneeSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->travelTo(Carbon::parse('2026-10-09 10:00', config('app.timezone')));
    }

    private function technician(bool $present = true): User
    {
        $department = Department::firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance']);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        $role = Role::firstOrCreate(['slug' => 'maintenance_tech'], ['name' => 'Technician']);
        $user = User::factory()->create(['employee_id' => $employee->id, 'role_id' => $role->id, 'is_active' => true]);
        $shift = Shift::create(['name' => 'Suggestion shift '.uniqid(), 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        EmployeeShiftAssignment::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'effective_date' => '2026-01-01', 'working_days' => [1,2,3,4,5]]);
        if ($present) {
            Attendance::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'date' => now()->toDateString(), 'status' => 'present', 'time_in' => now()->subHour()]);
        }
        return $user;
    }

    private function order(User $creator, array $overrides = []): MaintenanceWorkOrder
    {
        $order = (new MaintenanceWorkOrder)->forceFill(array_merge(['mwo_number' => 'SUGGEST-'.uniqid(),
            'maintainable_type' => 'machine', 'maintainable_id' => Machine::factory()->create(['status' => 'idle'])->id,
            'type' => 'corrective', 'priority' => 'high', 'description' => 'Suggestion test', 'status' => 'open',
            'created_by' => $creator->id, 'verification_required' => true], $overrides));
        $order->save();
        return $order;
    }

    public function test_ranking_uses_presence_load_and_reports_busy_and_inactive(): void
    {
        $a = $this->technician(); $b = $this->technician(); $unknown = $this->technician(false);
        $inactive = $this->technician(); $inactive->employee->forceFill(['status' => 'suspended'])->save();
        $order = $this->order($a);
        $this->order($a, ['assigned_to' => $a->employee_id, 'status' => 'assigned']);
        $result = app(MaintenanceAssigneeSuggestionService::class)->suggest($order, now(), now()->addHour());
        $this->assertSame($b->employee->hash_id, $result['suggested_employee_id']);
        $rows = collect($result['candidates'])->keyBy('id');
        $this->assertTrue($rows[$unknown->employee->hash_id]['can_attest_presence']);
        $this->assertSame('Employee is inactive.', $rows[$inactive->employee->hash_id]['blocked_reason']);
        $this->order($b, ['assigned_to' => $b->employee_id, 'status' => 'in_progress']);
        $this->assertFalse(app(MaintenanceAssigneeSuggestionService::class)->candidate($order, $b->employee, now(), now()->addHour())['can_assign']);
        $future = app(MaintenanceAssigneeSuggestionService::class)->candidate($order, $a->employee, now()->addDays(3), now()->addDays(3)->addHour());
        $this->assertSame('unknown', $future['presence']['state']);
    }

    public function test_known_absence_rejected_for_legacy_assignment(): void
    {
        $user = $this->technician(); $order = $this->order($user);
        Attendance::where('employee_id', $user->employee_id)->update(['status' => 'absent']);
        try {
            app(MaintenanceWorkOrderService::class)->assign($order, $user->employee_id, $user, ['expected_assignee_id' => null]);
            $this->fail('Changed attendance must reject assignment.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('employee_id', $error->errors());
        }
        $order->forceFill(['verification_required' => false])->save();
        try {
            app(MaintenanceWorkOrderService::class)->assign($order, $user->employee_id, $user, ['expected_assignee_id' => null]);
            $this->fail('Legacy orders must also reject stale attendance.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('employee_id', $error->errors());
        }
    }

    public function test_no_eligible_suggestion_and_fresh_start_rejects_absence(): void
    {
        $user = $this->technician(); $order = $this->order($user, ['verification_required' => false]);
        $service = app(MaintenanceWorkOrderService::class);
        $service->assign($order, $user->employee_id, $user, ['expected_assignee_id' => null]);
        Attendance::where('employee_id', $user->employee_id)->update(['status' => 'absent']);
        $result = app(MaintenanceAssigneeSuggestionService::class)->suggest($order, now(), now()->addHour());
        $this->assertNull($result['suggested_employee_id']);
        $this->assertFalse($result['candidates'][0]['can_attest_presence']);
        try { $service->start($order, $user); $this->fail('Fresh absence must block start.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('employee_id', $error->errors()); }
        $this->assertSame('assigned', $order->fresh()->status->value);
    }

    public function test_legacy_assignment_rejects_a_technician_busy_on_another_order(): void
    {
        $user = $this->technician();
        $order = $this->order($user, ['verification_required' => false]);
        $this->order($user, ['assigned_to' => $user->employee_id, 'status' => 'in_progress']);

        try {
            app(MaintenanceWorkOrderService::class)->assign($order, $user->employee_id, $user, ['expected_assignee_id' => null]);
            $this->fail('A technician already working another order must not be assigned.');
        } catch (ValidationException $error) {
            $this->assertSame('Technician is already working on another maintenance order. Refresh suggestions and choose again.', $error->errors()['employee_id'][0]);
        }
    }

    public function test_legacy_assignment_rejects_non_maintenance_employee(): void
    {
        $user = $this->technician();
        $order = $this->order($user, ['verification_required' => false]);
        $user->employee->department()->update(['code' => 'PROD']);

        try {
            app(MaintenanceWorkOrderService::class)->assign($order, $user->employee_id, $user, ['expected_assignee_id' => null]);
            $this->fail('A non-maintenance employee must not be assigned.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('employee_id', $error->errors());
        }
    }

    public function test_legacy_assignment_rejects_a_technician_outside_shift_coverage(): void
    {
        $user = $this->technician();
        $order = $this->order($user, ['verification_required' => false]);
        $shift = $user->employee->shiftAssignments()->firstOrFail()->shift;
        $shift->forceFill(['start_time' => '11:00', 'end_time' => '12:00'])->save();

        try {
            app(MaintenanceWorkOrderService::class)->assign($order, $user->employee_id, $user, ['expected_assignee_id' => null]);
            $this->fail('A technician outside shift coverage must not be assigned.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('shift', strtolower($error->errors()['employee_id'][0]));
        }
    }

    public function test_legacy_assignment_rejects_employee_with_unrelated_account_role(): void
    {
        $user = $this->technician();
        $order = $this->order($user, ['verification_required' => false]);
        $role = Role::firstOrCreate(['slug' => 'hr_officer'], ['name' => 'HR Officer']);
        $user->forceFill(['role_id' => $role->id])->save();

        try {
            app(MaintenanceWorkOrderService::class)->assign($order, $user->employee_id, $user, ['expected_assignee_id' => null]);
            $this->fail('An unrelated account role must not be assigned.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('employee_id', $error->errors());
        }
    }

    public function test_legacy_assignment_allows_unknown_future_attendance_without_attestation(): void
    {
        $user = $this->technician(false);
        $order = $this->order($user, ['verification_required' => false]);
        MaintenanceWindow::create([
            'maintainable_type' => 'machine', 'maintainable_id' => $order->maintainable_id,
            'work_order_id' => $order->id, 'planned_start_at' => now()->addDays(3),
            'planned_end_at' => now()->addDays(3)->addHour(), 'state' => 'confirmed',
            'confirmed_by' => $user->id, 'confirmed_at' => now(),
        ]);

        $assigned = app(MaintenanceWorkOrderService::class)->assign($order, $user->employee_id, $user, ['expected_assignee_id' => null]);

        $this->assertSame($user->employee_id, $assigned->assigned_to);
    }

    public function test_attestation_start_and_stale_handover_checks(): void
    {
        $user = $this->technician(false); $replacement = $this->technician(); $order = $this->order($user, ['verification_required' => false]);
        $role = Role::firstOrCreate(['slug' => 'maintenance_supervisor'], ['name' => 'Maintenance Supervisor']);
        $supervisor = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $service = app(MaintenanceWorkOrderService::class);
        try {
            $service->assign($order, $user->employee_id, $user, ['expected_assignee_id' => null, 'presence_attestation_reason' => 'Physically present in workshop.']);
            $this->fail('Only a maintenance supervisor may attest presence.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('employee_id', $error->errors());
        }
        $service->assign($order, $user->employee_id, $supervisor, ['expected_assignee_id' => null, 'presence_attestation_reason' => 'Physically present in workshop.']);
        $service->start($order, $user);
        foreach ([['expected_assignee_id' => null, 'handover_note' => 'Repair pending.'], ['expected_assignee_id' => $user->employee_id]] as $choice) {
            try { $service->assign($order, $replacement->employee_id, $supervisor, $choice); $this->fail('Invalid handover must reject.'); }
            catch (ValidationException $error) { $this->assertNotEmpty($error->errors()); }
        }
        $service->assign($order, $replacement->employee_id, $supervisor, ['expected_assignee_id' => $user->employee_id, 'handover_note' => 'Motor removed; isolate power.']);
        $this->assertDatabaseHas('maintenance_logs', ['work_order_id' => $order->id, 'logged_by' => $supervisor->id]);
        $from = Employee::findOrFail($user->employee_id);
        $to = Employee::findOrFail($replacement->employee_id);
        $this->assertStringStartsWith(
            "Assignee changed from {$from->full_name} ({$from->employee_no}) to {$to->full_name} ({$to->employee_no}).",
            $order->logs()->reorder()->latest('id')->firstOrFail()->description,
        );
    }
}
