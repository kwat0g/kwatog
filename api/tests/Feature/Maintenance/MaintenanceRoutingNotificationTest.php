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
use App\Modules\Maintenance\Events\MaintenanceWorkOrderCreated;
use App\Modules\Maintenance\Listeners\NotifyMaintenanceWorkOrder;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MaintenanceRoutingNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Event::fake();
    }

    public function test_creation_routes_both_types_once_to_other_active_supervisors(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $otherSupervisor = $this->actor('maintenance_supervisor');
        $inactive = $this->actor('maintenance_supervisor');
        $revoked = $this->actor('maintenance_supervisor');
        $inactive->update(['is_active' => false]);
        $this->revokeView($revoked, $supervisor);
        $listener = app(NotifyMaintenanceWorkOrder::class);
        foreach (['preventive', 'corrective'] as $type) {
            $order = $this->order($supervisor, ['type' => $type]);
            $listener->handle(new MaintenanceWorkOrderCreated($order));
            $listener->handle(new MaintenanceWorkOrderCreated($order));
        }
        $this->assertSame(2, DB::table('notifications')->where('type', 'maintenance.assignment_needed')->count());
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $supervisor->id,
            'type' => 'maintenance.assignment_needed',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $otherSupervisor->id,
            'type' => 'maintenance.assignment_needed',
        ]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $inactive->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $revoked->id]);
        $data = json_decode((string) DB::table('notifications')->where('type', 'maintenance.assignment_needed')->value('data'), true);
        $this->assertStringContainsString('/maintenance/work-orders/', $data['link_to']);
        $this->assertSame(MaintenanceWorkOrder::class, $data['subject_type']);
    }

    public function test_missing_supervisor_records_setup_failure(): void
    {
        $order = $this->order($this->actor('production_manager'));
        Log::shouldReceive('error')->once()->with('Maintenance notification setup failure: no active maintenance supervisor.', ['work_order_id' => $order->id]);
        app(NotifyMaintenanceWorkOrder::class)->handle(new MaintenanceWorkOrderCreated($order));
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_assignee_options_and_assignment_require_the_linked_page_permission(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $technician = $this->actor('maintenance_tech');
        $employee = $this->actor('employee');
        $revokedTechnician = $this->actor('maintenance_tech');
        $revokedEmployee = $this->actor('employee');
        $this->revokePermission($revokedTechnician, $supervisor, 'maintenance.view');
        $this->revokePermission($revokedEmployee, $supervisor, 'maintenance.work.assigned');

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/maintenance/work-orders/assignees')
            ->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($technician->employee->hash_id, $ids);
        $this->assertContains($employee->employee->hash_id, $ids);
        $this->assertNotContains($revokedTechnician->employee->hash_id, $ids);
        $this->assertNotContains($revokedEmployee->employee->hash_id, $ids);

        $order = $this->order($supervisor);
        foreach ([$revokedTechnician, $revokedEmployee] as $user) {
            try {
                app(MaintenanceWorkOrderService::class)->assign(
                    $order, $user->employee_id, $supervisor, ['expected_assignee_id' => null],
                );
                $this->fail('A user without access to the linked page must not be assigned.');
            } catch (\Illuminate\Validation\ValidationException $error) {
                $this->assertArrayHasKey('employee_id', $error->errors());
            }
        }
    }

    public function test_critical_escalation_is_due_once_and_assignment_closes_episode(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $revokedSupervisor = $this->actor('maintenance_supervisor');
        $this->revokeView($revokedSupervisor, $supervisor);
        $manager = $this->actor('production_manager');
        $technician = $this->actor('maintenance_tech');
        $this->makePresent($technician);
        $order = $this->order($supervisor, ['created_at' => now()->subMinutes(16)]);
        $this->order($supervisor, ['created_at' => now()->subMinutes(14)]);
        $this->order($supervisor, ['priority' => 'high', 'created_at' => now()->subHour()]);
        $this->artisan('maintenance:check-assignment')->assertSuccessful();
        $this->artisan('maintenance:check-assignment')->assertSuccessful();
        $this->assertSame(2, DB::table('notifications')->where('type', 'maintenance.assignment_escalated')->count());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $manager->id, 'type' => 'maintenance.assignment_escalated']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $revokedSupervisor->id, 'type' => 'maintenance.assignment_escalated']);
        $service = app(MaintenanceWorkOrderService::class);
        $service->assign($order, $technician->employee_id, $supervisor, ['expected_assignee_id' => null]);
        $service->assign($order, $technician->employee_id, $supervisor, ['expected_assignee_id' => $technician->employee_id]);
        $this->assertSame(1, DB::table('notifications')->where('type', 'maintenance.assigned')->count());
        $assignmentData = json_decode((string) DB::table('notifications')->where('type', 'maintenance.assigned')->value('data'), true);
        $this->assertSame(MaintenanceWorkOrder::class, $assignmentData['subject_type']);
        $this->assertSame((string) $order->id, $assignmentData['subject_id']);
        $this->artisan('maintenance:check-assignment')->assertSuccessful();
        $this->assertSame(2, DB::table('notifications')->where('type', 'maintenance.assignment_escalated')->count());
        $this->assertSame('assigned', $order->fresh()->status->value);
    }

    public function test_queues_filter_before_pagination(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $matching = $this->order($supervisor);
        $this->order($supervisor, ['priority' => 'high', 'status' => 'completed']);
        $service = app(MaintenanceWorkOrderService::class);
        foreach (['unassigned', 'critical'] as $queue) {
            $result = $service->list(['queue' => $queue, 'per_page' => 1]);
            $this->assertSame(1, $result->total());
            $this->assertSame($matching->id, $result->items()[0]->id);
        }
    }

    private function actor(string $slug): User
    {
        $department = Department::firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance']);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);

        return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id, 'is_active' => true]);
    }

    private function order(User $creator, array $overrides = []): MaintenanceWorkOrder
    {
        $order = (new MaintenanceWorkOrder)->forceFill(array_merge([
            'mwo_number' => 'ROUTING-'.uniqid(), 'maintainable_type' => 'machine',
            'maintainable_id' => Machine::factory()->create()->id, 'type' => 'corrective',
            'priority' => 'critical', 'description' => 'Routing test', 'status' => 'open',
            'created_by' => $creator->id,
        ], $overrides));
        $order->save();

        return $order;
    }

    private function makePresent(User $user): void
    {
        $shift = Shift::create([
            'name' => 'Routing maintenance shift', 'start_time' => '00:00', 'end_time' => '23:59', 'is_active' => true,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $user->employee_id, 'shift_id' => $shift->id,
            'effective_date' => now()->toDateString(), 'working_days' => [now()->dayOfWeekIso],
        ]);
        Attendance::create([
            'employee_id' => $user->employee_id, 'shift_id' => $shift->id,
            'date' => now()->toDateString(), 'status' => 'present', 'time_in' => now()->subHour(),
        ]);
    }

    private function revokeView(User $user, User $grantedBy): void
    {
        $this->revokePermission($user, $grantedBy, 'maintenance.view');
    }

    private function revokePermission(User $user, User $grantedBy, string $permission): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => DB::table('permissions')->where('slug', $permission)->value('id'),
            'type' => 'revoke',
            'granted_by' => $grantedBy->id,
            'reason' => 'Audience regression test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->flushPermissionsCache();
    }
}
