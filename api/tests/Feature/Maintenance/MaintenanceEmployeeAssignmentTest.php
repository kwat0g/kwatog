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
use App\Modules\HR\Models\Position;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenanceEmployeeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Event::fake();
        $this->travelTo(Carbon::parse('2026-10-10 10:00', config('app.timezone')));
    }

    private function actor(string $role = 'employee', string $department = 'MAINT'): User
    {
        $dept = Department::firstOrCreate(['code' => $department], ['name' => $department, 'is_active' => true]);
        $position = Position::firstOrCreate(['department_id' => $dept->id, 'title' => 'Maintenance Employee']);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'position_id' => $position->id, 'status' => 'active']);
        $user = User::factory()->create(['employee_id' => $employee->id, 'role_id' => Role::where('slug', $role)->value('id'), 'is_active' => true]);
        $shift = Shift::create(['name' => 'Employee maintenance '.uniqid(), 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        EmployeeShiftAssignment::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'effective_date' => '2026-01-01', 'working_days' => [1,2,3,4,5,6,7]]);
        Attendance::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'date' => now()->toDateString(), 'status' => 'present', 'time_in' => now()->subHour()]);
        return $user;
    }

    private function order(User $supervisor, array $changes = []): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create(array_replace(['mwo_number' => 'EMP-MWO-'.uniqid(),
            'maintainable_type' => 'machine', 'maintainable_id' => Machine::factory()->create(['status' => 'idle'])->id,
            'type' => 'corrective', 'priority' => 'low', 'description' => 'Employee maintenance scenario',
            'status' => 'open', 'created_by' => $supervisor->id, 'verification_required' => false], $changes));
    }

    private function assign(User $supervisor, User $employee, MaintenanceWorkOrder $order): void
    {
        $this->actingAs($supervisor)->patchJson('/api/v1/maintenance/work-orders/'.$order->hash_id.'/assign',
            ['employee_id' => $employee->employee->hash_id, 'expected_assignee_id' => null])->assertOk();
    }

    public function test_employee_assignment_records_parts_and_supervisor_closes_without_global_permissions(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $employee = $this->actor();
        $order = $this->order($supervisor);
        $url = '/api/v1/maintenance/work-orders/'.$order->hash_id;
        $this->actingAs($supervisor)->getJson($url.'/assignee-suggestions')->assertOk()
            ->assertJsonFragment(['id' => $employee->employee->hash_id, 'can_assign' => true]);
        $this->assign($supervisor, $employee, $order);
        $this->actingAs($employee)->getJson($url)->assertOk()->assertJsonPath('data.can_record_work', false);
        $this->actingAs($employee)->patchJson($url.'/start')->assertForbidden();
        $this->actingAs($supervisor)->patchJson($url.'/start')->assertOk();
        $this->actingAs($employee)->getJson($url)->assertOk()->assertJsonPath('data.can_record_work', true);
        $record = $this->actingAs($employee)->postJson($url.'/work-records',
            ['finding' => 'Loose screw', 'action_taken' => 'Replace screw', 'result' => 'passed'])->assertCreated();
        $part = Item::factory()->create(['item_type' => 'spare_part', 'unit_of_measure' => 'pcs', 'is_active' => true]);
        $location = WarehouseLocation::factory()->create(['is_active' => true, 'is_blocked' => false]);
        StockLevel::create(['item_id' => $part->id, 'location_id' => $location->id, 'quantity' => '5', 'reserved_quantity' => '0', 'weighted_avg_cost' => '10', 'lock_version' => 0]);
        $this->actingAs($employee)->getJson('/api/v1/maintenance/spare-part-options?work_order_id='.$order->hash_id)->assertOk();
        $this->actingAs($employee)->getJson('/api/v1/maintenance/spare-part-stock?item_id='.$part->hash_id.'&work_order_id='.$order->hash_id)->assertOk();
        $usage = $this->actingAs($employee)->postJson($url.'/spare-parts', ['item_id' => $part->hash_id,
            'location_id' => $location->hash_id, 'quantity' => '2', 'work_record_id' => $record->json('data.id'), 'operation_key' => (string) Str::uuid()])->assertCreated();
        $warehouse = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $this->actingAs($warehouse)->postJson('/api/v1/inventory/maintenance-part-requests/'.$usage->json('data.id').'/handover',
            ['received_by_employee_id' => $employee->employee->hash_id, 'expected_quantity' => '2'])->assertOk();
        $usageId = \App\Modules\Maintenance\Models\SparePartUsage::where('work_order_id', $order->id)->firstOrFail()->hash_id;
        $this->actingAs($employee)->postJson($url.'/spare-parts/'.$usageId.'/returns',
            ['quantity' => '1', 'operation_key' => (string) Str::uuid()])->assertOk();
        $this->actingAs($employee)->postJson($url.'/reconcile-parts', ['confirm_consumed' => true])->assertOk();
        $this->actingAs($employee)->patchJson($url.'/complete')->assertForbidden();
        $this->actingAs($employee)->patchJson($url.'/cancel', ['reason' => 'Cannot cancel as employee'])->assertForbidden();
        $this->actingAs($employee)->postJson($url.'/verify', ['decision' => 'accept'])->assertForbidden();
        $this->actingAs($supervisor)->patchJson($url.'/complete', ['remarks' => 'Reviewed employee work'])->assertOk();
        $this->actingAs($employee)->getJson($url)->assertOk()->assertJsonPath('data.can_record_work', false);
        $this->actingAs($employee)->postJson($url.'/work-records', ['finding' => 'late', 'action_taken' => 'late', 'result' => 'passed'])->assertForbidden();
        $this->assertSame('employee', $employee->fresh()->role->slug);
        $this->assertFalse($employee->fresh()->hasPermission('maintenance.wo.complete'));
        $this->assertDatabaseHas('stock_levels', ['item_id' => $part->id, 'quantity' => '4.000']);
        $this->assertSame('10.00', $order->fresh()->cost);
    }

    public function test_self_service_assignment_cannot_change_after_work_starts(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $first = $this->actor(); $second = $this->actor();
        $order = $this->order($supervisor); $other = $this->order($supervisor);
        $this->assign($supervisor, $first, $order);
        $url = '/api/v1/maintenance/work-orders/'.$order->hash_id;
        $this->actingAs($supervisor)->patchJson($url.'/start')->assertOk();
        $this->actingAs($first)->getJson('/api/v1/maintenance/work-orders?assigned_to='.$second->employee->hash_id)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($first)->getJson('/api/v1/maintenance/work-orders/'.$other->hash_id)->assertNotFound();
        $this->actingAs($second)->postJson($url.'/work-records', ['finding' => 'fake', 'action_taken' => 'fake', 'result' => 'passed'])->assertForbidden();
        $this->actingAs($first)->getJson('/api/v1/maintenance/spare-part-options')->assertForbidden();
        $this->actingAs($first)->getJson('/api/v1/maintenance/spare-part-options?work_order_id='.$other->hash_id)->assertForbidden();
        $this->actingAs($first)->getJson('/api/v1/maintenance/spare-part-stock?item_id=unrelated')->assertForbidden();
        $this->actingAs($first)->postJson($url.'/work-records', ['finding' => 'Inspect', 'action_taken' => 'Tightened', 'result' => 'passed'])->assertCreated();
        $this->actingAs($supervisor)->patchJson($url.'/assign', ['employee_id' => $second->employee->hash_id,
            'expected_assignee_id' => $first->employee->hash_id, 'handover_note' => 'Handover for remaining repair'])->assertUnprocessable();
        $this->assertSame($first->employee_id, $order->fresh()->assigned_to);
        $this->actingAs($first)->postJson($url.'/work-records', ['finding' => 'Still assigned', 'action_taken' => 'Continued inspection', 'result' => 'passed'])->assertCreated();
        $this->actingAs($second)->getJson($url)->assertNotFound();
        $this->actingAs($second)->postJson($url.'/work-records', ['finding' => 'stale', 'action_taken' => 'stale', 'result' => 'passed'])->assertForbidden();
        $this->actingAs($first)->postJson('/api/v1/maintenance/work-orders', [])->assertForbidden();
    }

    public function test_employee_assignment_still_requires_department_account_presence_and_free_workload(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $employee = $this->actor(); $outside = $this->actor('employee', 'PROD');
        $order = $this->order($supervisor); $url = '/api/v1/maintenance/work-orders/'.$order->hash_id.'/assign';
        $this->actingAs($supervisor)->patchJson($url, ['employee_id' => $outside->employee->hash_id, 'expected_assignee_id' => null])->assertUnprocessable();
        Attendance::where('employee_id', $employee->employee_id)->update(['status' => 'absent']);
        $this->actingAs($supervisor)->patchJson($url, ['employee_id' => $employee->employee->hash_id, 'expected_assignee_id' => null])->assertUnprocessable();
        Attendance::where('employee_id', $employee->employee_id)->update(['status' => 'present']);
        $this->order($supervisor, ['assigned_to' => $employee->employee_id, 'status' => 'in_progress']);
        $this->actingAs($supervisor)->patchJson($url, ['employee_id' => $employee->employee->hash_id, 'expected_assignee_id' => null])->assertUnprocessable();
        $employee->update(['is_active' => false]);
        $this->actingAs($supervisor)->patchJson($url, ['employee_id' => $employee->employee->hash_id, 'expected_assignee_id' => null])->assertUnprocessable();
        $this->assertNull($order->fresh()->assigned_to);
    }

    public function test_verified_employee_repair_freezes_recording_and_requires_independent_acceptance(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $employee = $this->actor();
        $manager = $this->actor('production_manager', 'PROD');
        $order = $this->order($supervisor, ['verification_required' => true]);
        $this->assign($supervisor, $employee, $order);
        $url = '/api/v1/maintenance/work-orders/'.$order->hash_id;
        $this->actingAs($supervisor)->patchJson($url.'/start')->assertOk();
        $record = ['finding' => 'Guard loose', 'action_taken' => 'Tightened and tested', 'result' => 'passed'];
        $this->actingAs($employee)->postJson($url.'/work-records', $record)->assertCreated();
        $evidence = ['diagnosis' => 'Loose guard fastener', 'repair_action' => 'Tightened fastener', 'functional_test_result' => 'Guard held during test'];
        $this->actingAs($employee)->postJson($url.'/submit-repair', $evidence)->assertForbidden();
        $this->assertContains('submit_repair', $this->actingAs($supervisor)->getJson($url)->assertOk()->json('data.available_actions'));
        $this->actingAs($supervisor)->postJson($url.'/submit-repair', $evidence)->assertOk();
        $this->actingAs($employee)->postJson($url.'/work-records', $record)->assertForbidden();
        $this->actingAs($supervisor)->postJson($url.'/verify', ['decision' => 'accept'])->assertForbidden();
        $this->actingAs($manager)->postJson($url.'/verify', ['decision' => 'reject', 'reason' => 'Retest guard under load'])->assertOk();
        $this->actingAs($employee)->postJson($url.'/work-records', $record)->assertCreated();
        $this->actingAs($supervisor)->postJson($url.'/submit-repair', $evidence)->assertOk();
        $this->actingAs($manager)->postJson($url.'/verify', ['decision' => 'accept'])->assertOk();
        $this->actingAs($employee)->getJson($url)->assertJsonPath('data.can_record_work', false);
        $this->actingAs($employee)->postJson($url.'/work-records', $record)->assertForbidden();
    }

    public function test_supervisor_cancellation_and_employee_status_changes_revoke_recording(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $employee = $this->actor();
        $order = $this->order($supervisor);
        $this->assign($supervisor, $employee, $order);
        $url = '/api/v1/maintenance/work-orders/'.$order->hash_id;
        $this->actingAs($supervisor)->patchJson($url.'/start')->assertOk();
        $record = ['finding' => 'Inspection', 'action_taken' => 'Checked equipment', 'result' => 'in_progress'];
        $employee->employee->department->update(['is_active' => false]);
        $this->actingAs($employee)->postJson($url.'/work-records', $record)->assertForbidden();
        $employee->employee->department->update(['is_active' => true]);
        $employee->employee->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($employee)->postJson($url.'/work-records', $record)->assertForbidden();
        $employee->employee->forceFill(['status' => 'active'])->save();
        $this->actingAs($employee)->postJson($url.'/work-records', $record)->assertCreated();
        $this->actingAs($supervisor)->postJson($url.'/reconcile-parts', ['inspection_reason' => 'Physical inspection completed'])->assertOk();
        $this->actingAs($supervisor)->patchJson($url.'/cancel', ['reason' => 'Duplicate order after physical inspection'])->assertOk();
        $this->actingAs($employee)->postJson($url.'/work-records', $record)->assertForbidden();
        $this->actingAs($employee)->postJson($url.'/reconcile-parts', [])->assertForbidden();
        $this->actingAs($employee)->getJson($url)->assertOk()->assertJsonPath('data.can_record_work', false);
    }
}
