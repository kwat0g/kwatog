<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Jobs\GeneratePreventiveMaintenanceJob;
use App\Common\Services\SettingsService;
use App\Modules\Maintenance\Services\MaintenanceScheduleService;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenanceRoleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function confirmParts(string $requestId, User $technician): void
    {
        $request = \App\Modules\Maintenance\Models\MaintenancePartRequest::findOrFail(app('hashids')->decode($requestId)[0]);
        $warehouse = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $this->actingAs($warehouse)->postJson('/api/v1/inventory/maintenance-part-requests/'.$requestId.'/handover',
            ['received_by_employee_id' => $technician->employee->hash_id, 'expected_quantity' => $request->quantity])->assertOk();
        $this->actingAs($technician);
    }

    public function test_technician_claims_unassigned_order_and_other_technician_cannot_change_it(): void
    {
        $first = $this->actor('maintenance_tech');
        $second = $this->actor('maintenance_tech');
        $order = $this->order($first);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";

        $this->actingAs($first)->patchJson($url.'/start')->assertOk();
        $this->assertSame($first->employee_id, $order->fresh()->assigned_to);
        $this->assertSame('in_progress', $order->fresh()->status->value);

        $this->actingAs($second)->patchJson($url.'/complete', ['remarks' => 'Wrong actor'])->assertForbidden();
        $this->actingAs($second)->patchJson($url.'/cancel', ['reason' => 'Wrong actor'])->assertForbidden();
        $this->actingAs($second)->postJson($url.'/logs', ['description' => 'Wrong actor'])->assertForbidden();
        $this->assertSame([], $this->actingAs($second)->getJson($url)->assertOk()->json('data.available_actions'));
        $this->assertSame('in_progress', $order->fresh()->status->value);
    }

    public function test_non_admin_supervisor_can_assign_before_start_but_not_change_worker_after_start(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $first = $this->actor('maintenance_tech');
        $second = $this->actor('maintenance_tech');
        $outsider = $this->actor('employee');
        $order = $this->order($first);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";

        $this->actingAs($supervisor)->getJson('/api/v1/maintenance/work-orders/assignees')->assertOk();
        $this->actingAs($supervisor)->getJson('/api/v1/maintenance/schedules/target-options?type=machine')->assertOk();
        $this->actingAs($supervisor)->patchJson($url.'/assign', ['employee_id' => $outsider->employee->hash_id])->assertUnprocessable();
        $this->actingAs($supervisor)->patchJson($url.'/assign', [
            'employee_id' => $first->employee->hash_id, 'expected_assignee_id' => null,
        ])->assertOk();
        $this->actingAs($supervisor)->patchJson($url.'/assign', [
            'employee_id' => $second->employee->hash_id,
            'expected_assignee_id' => $first->employee->hash_id,
        ])->assertOk();
        $this->actingAs($second)->patchJson($url.'/start')->assertOk();

        $this->assertNotContains('assign', $this->actingAs($supervisor)->getJson($url)->assertOk()->json('data.available_actions'));

        $this->actingAs($supervisor)->patchJson($url.'/assign', [
            'employee_id' => $first->employee->hash_id,
            'expected_assignee_id' => $second->employee->hash_id,
            'handover_note' => 'Inspection assigned to replacement technician.',
        ])->assertUnprocessable();
        $this->assertSame($second->employee_id, $order->fresh()->assigned_to);
        $this->actingAs($first)->patchJson($url.'/complete')->assertForbidden();
        $this->actingAs($second)->postJson($url.'/work-records', [
            'finding' => 'Machine ready for service', 'action_taken' => 'Completed inspection', 'result' => 'passed',
        ])->assertCreated();
        $this->actingAs($second)->patchJson($url.'/complete', ['remarks' => 'Completed by assigned technician'])->assertOk();
        $this->assertSame('completed', $order->fresh()->status->value);
    }

    public function test_cancellation_requires_a_reason_and_records_it(): void
    {
        $technician = $this->actor('maintenance_tech');
        $order = $this->order($technician);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}/cancel";

        $this->actingAs($technician)->patchJson($url)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($technician)->patchJson($url, ['reason' => ''])->assertUnprocessable();
        $this->assertSame('open', $order->fresh()->status->value);
        $this->actingAs($technician)->patchJson($url, ['reason' => 'Repair no longer needed'])->assertOk();
        $this->assertSame('Repair no longer needed', $order->fresh()->remarks);
    }

    public function test_description_edit_keeps_original_schedule_due_date(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $machine = Machine::factory()->create(['status' => 'idle']);
        $schedule = app(MaintenanceScheduleService::class)->create([
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'description' => 'Inspect belts',
            'interval_type' => 'days',
            'interval_value' => 7,
        ]);
        $due = $schedule->next_due_at->toISOString();

        $this->actingAs($supervisor)->putJson("/api/v1/maintenance/schedules/{$schedule->hash_id}", [
            'description' => 'Inspect belts and guards',
        ])->assertOk();

        $this->assertSame($due, $schedule->fresh()->next_due_at->toISOString());
    }

    public function test_stale_schedule_cannot_generate_work_order(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $machine = Machine::factory()->create(['status' => 'idle']);
        $schedule = app(MaintenanceScheduleService::class)->create([
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'description' => 'Inspect bearings',
            'interval_type' => 'days',
            'interval_value' => 7,
        ]);
        $schedule->forceFill(['is_active' => false, 'next_due_at' => now()->subDay()])->save();

        try {
            app(MaintenanceWorkOrderService::class)->create([], $supervisor, $schedule);
            $this->fail('Inactive schedule generated a work order.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('schedule_id', $exception->errors());
        }
        $this->assertDatabaseMissing('maintenance_work_orders', ['schedule_id' => $schedule->id]);
    }

    public function test_automation_uses_non_admin_supervisor_and_respects_module_toggle(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $machine = Machine::factory()->create(['status' => 'idle']);
        $schedule = app(MaintenanceScheduleService::class)->create([
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'description' => 'Scheduled inspection', 'interval_type' => 'days', 'interval_value' => 7,
        ]);
        $schedule->forceFill(['next_due_at' => now()->subDay()])->save();
        $settings = app(SettingsService::class);
        $settings->set('modules.maintenance', false);

        app()->call([app(GeneratePreventiveMaintenanceJob::class), 'handle']);
        $this->assertDatabaseMissing('maintenance_work_orders', ['schedule_id' => $schedule->id]);

        $settings->set('modules.maintenance', true);
        app()->call([app(GeneratePreventiveMaintenanceJob::class), 'handle']);
        $this->assertDatabaseHas('maintenance_work_orders', [
            'schedule_id' => $schedule->id,
            'created_by' => $supervisor->id,
        ]);
    }

    public function test_schedule_details_show_runtime_and_shot_progress(): void
    {
        $supervisor = $this->actor('maintenance_supervisor');
        $machine = Machine::factory()->create(['running_hours_total' => '100.00']);
        $hours = app(MaintenanceScheduleService::class)->create([
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'description' => 'Oil service', 'interval_type' => 'hours', 'interval_value' => 10,
        ]);
        $machine->forceFill(['running_hours_total' => '102.00'])->save();
        $mold = Mold::query()->create([
            'mold_code' => 'ROLE-'.uniqid(), 'name' => 'Test mold',
            'product_id' => Product::factory()->create()->id,
            'cavity_count' => 1, 'cycle_time_seconds' => 10,
            'output_rate_per_hour' => 300, 'max_shots_before_maintenance' => 100,
            'lifetime_max_shots' => 1000, 'current_shot_count' => 20,
            'status' => 'available',
        ]);
        $shots = app(MaintenanceScheduleService::class)->create([
            'maintainable_type' => 'mold', 'maintainable_id' => $mold->id,
            'description' => 'Mold service', 'interval_type' => 'shots', 'interval_value' => 100,
        ]);

        $this->actingAs($supervisor)->getJson("/api/v1/maintenance/schedules/{$hours->hash_id}")
            ->assertOk()->assertJsonPath('data.progress.current', 2)->assertJsonPath('data.progress.target', 10);
        $this->actingAs($supervisor)->getJson("/api/v1/maintenance/schedules/{$shots->hash_id}")
            ->assertOk()->assertJsonPath('data.progress.current', 20)->assertJsonPath('data.progress.target', 100);
        $list = $this->actingAs($supervisor)->getJson('/api/v1/maintenance/schedules')->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertNotNull($list[0]['maintainable']);
        $this->assertNotNull($list[1]['maintainable']);
    }

    public function test_only_assigned_technician_can_return_unused_part_once(): void
    {
        $technician = $this->actor('maintenance_tech');
        $other = $this->actor('maintenance_tech');
        $order = $this->order($technician);
        $url = "/api/v1/maintenance/work-orders/{$order->hash_id}";
        app(SettingsService::class)->set('modules.accounting', false);
        $item = Item::factory()->create(['item_type' => 'spare_part', 'unit_of_measure' => 'kg']);
        $location = WarehouseLocation::factory()->create();
        $level = StockLevel::query()->create([
            'item_id' => $item->id, 'location_id' => $location->id,
            'quantity' => '3.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '10.0000',
        ]);
        $this->actingAs($technician)->patchJson($url.'/start')->assertOk();
        $workRecordId = $this->actingAs($technician)->postJson($url.'/work-records', [
            'finding' => 'Part needed for repair', 'action_taken' => 'Inspected assembly', 'result' => 'needs_follow_up',
        ])->assertCreated()->json('data.id');
        $usageId = $this->actingAs($technician)->postJson($url.'/spare-parts', [
            'item_id' => $item->hash_id, 'location_id' => $location->hash_id,
            'work_record_id' => $workRecordId, 'quantity' => '1.000',
            'operation_key' => 'b68cd5f8-e409-478e-a63e-e22f0e88ace0',
        ])->assertCreated()->json('data.id');
        $this->confirmParts($usageId, $technician);
        $usageId = \App\Modules\Maintenance\Models\SparePartUsage::where('work_order_id', $order->id)->firstOrFail()->hash_id;
        $returnUrl = $url.'/spare-parts/'.$usageId.'/returns';
        $returnBody = ['quantity' => '1.000', 'operation_key' => 'c98b873d-5b54-4b9b-9fa6-e304f7fe8494'];
        $this->actingAs($other)->postJson($returnUrl, $returnBody)->assertForbidden();
        $this->actingAs($technician)->postJson($returnUrl, $returnBody)->assertOk()
            ->assertJsonPath('data.returned_quantity', '1.000');
        $this->actingAs($technician)->postJson($returnUrl, $returnBody)->assertOk();
        $this->assertSame('3.000', $level->fresh()->quantity);
        $this->assertSame('0.00', $order->fresh()->cost);
    }

    public function test_non_admin_roles_complete_a_verified_repair_with_net_parts_and_rework(): void
    {
        app(SettingsService::class)->set('maintenance.closed_loop.enabled', true);
        app(SettingsService::class)->set('modules.accounting', false);
        $supervisor = $this->actor('maintenance_supervisor');
        $technician = $this->actor('maintenance_tech');
        $manager = $this->actor('production_manager');
        $outsider = $this->actor('employee');
        $machine = Machine::factory()->create(['status' => 'idle']);
        $payload = [
            'maintainable_type' => 'machine', 'maintainable_id' => $machine->hash_id,
            'type' => 'corrective', 'priority' => 'high', 'description' => 'Repair failed drive',
        ];

        $this->actingAs($outsider)->postJson('/api/v1/maintenance/work-orders', $payload)->assertForbidden();
        $id = $this->actingAs($supervisor)->postJson('/api/v1/maintenance/work-orders', $payload)
            ->assertCreated()->json('data.id');
        $order = MaintenanceWorkOrder::query()->where('maintainable_id', $machine->id)->firstOrFail();
        $this->assertTrue($order->verification_required);
        $url = "/api/v1/maintenance/work-orders/{$id}";
        $this->patchJson($url.'/assign', [
            'employee_id' => $technician->employee->hash_id, 'expected_assignee_id' => null,
        ])->assertOk();
        $this->actingAs($technician)->patchJson($url.'/start')->assertOk();

        $item = Item::factory()->create(['item_type' => 'spare_part', 'unit_of_measure' => 'pcs']);
        $location = WarehouseLocation::factory()->create();
        $level = StockLevel::query()->create([
            'item_id' => $item->id, 'location_id' => $location->id,
            'quantity' => '3.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '10.0000',
        ]);
        $record = $this->postJson($url.'/work-records', [
            'finding' => 'Drive coupling worn', 'action_taken' => 'Removed coupling', 'result' => 'in_progress',
        ])->assertCreated()->json('data.id');
        $usage = $this->postJson($url.'/spare-parts', [
            'item_id' => $item->hash_id, 'location_id' => $location->hash_id,
            'work_record_id' => $record, 'quantity' => '2', 'operation_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
        $this->confirmParts($usage, $technician);
        $usage = \App\Modules\Maintenance\Models\SparePartUsage::where('work_order_id', $order->id)->firstOrFail()->hash_id;
        $this->actingAs($technician)->postJson($url.'/spare-parts/'.$usage.'/returns', [
            'quantity' => '1', 'operation_key' => (string) Str::uuid(),
        ])->assertOk();
        $this->postJson($url.'/work-records', [
            'finding' => 'Trial still vibrates', 'action_taken' => 'Adjusted alignment', 'result' => 'needs_follow_up',
        ])->assertCreated();
        $evidence = [
            'diagnosis' => 'Worn coupling', 'repair_action' => 'Replaced coupling and aligned drive',
            'functional_test_result' => 'Rated-load cycle ran without vibration',
        ];
        $this->postJson($url.'/submit-repair', $evidence)->assertUnprocessable()
            ->assertJsonValidationErrors('work_record');
        $this->postJson($url.'/work-records', [
            'finding' => 'Drive stable', 'action_taken' => 'Ran rated-load cycle', 'result' => 'passed',
        ])->assertCreated();
        $this->travel(5)->minutes();
        $this->postJson($url.'/submit-repair', $evidence)->assertUnprocessable()
            ->assertJsonValidationErrors('parts_reconciliation');
        $this->postJson($url.'/reconcile-parts', ['confirm_consumed' => true])->assertOk();
        $this->postJson($url.'/submit-repair', $evidence)->assertOk();
        $this->actingAs($manager)->postJson($url.'/verify', [
            'decision' => 'reject', 'reason' => 'Repeat trial with guard installed',
        ])->assertOk();
        $this->actingAs($technician)->postJson($url.'/submit-repair', $evidence)->assertUnprocessable()
            ->assertJsonValidationErrors('work_record');
        $this->postJson($url.'/work-records', [
            'finding' => 'Guard installed', 'action_taken' => 'Retested under rated load', 'result' => 'passed',
        ])->assertCreated();
        $this->postJson($url.'/submit-repair', $evidence)->assertOk();
        $this->actingAs($technician)->postJson($url.'/verify', ['decision' => 'accept'])->assertForbidden();
        $this->actingAs($manager)->postJson($url.'/verify', ['decision' => 'accept'])->assertOk();

        $this->assertSame('completed', $order->fresh()->status->value);
        $this->assertSame('10.00', $order->fresh()->cost);
        $this->assertSame('2.000', $level->fresh()->quantity);
        $this->assertSame('idle', $machine->fresh()->status->value);
        $this->assertGreaterThanOrEqual(5, $order->fresh()->downtime_minutes);
    }

    private function actor(string $roleSlug): User
    {
        $department = Department::query()->firstOrCreate(['code' => 'MAINT'], ['name' => 'Maintenance & Engineering']);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $shift = Shift::create([
            'name' => 'Role workflow '.uniqid(),
            'start_time' => now()->subHour()->format('H:i:s'),
            'end_time' => now()->addHours(6)->format('H:i:s'),
            'is_active' => true,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'shift_id' => $shift->id,
            'effective_date' => now()->toDateString(), 'working_days' => [now()->dayOfWeekIso],
        ]);

        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id')
                ?? Role::query()->firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug])->id,
            'employee_id' => $employee->id,
        ]);
        Attendance::create([
            'employee_id' => $employee->id, 'shift_id' => $shift->id,
            'date' => now()->toDateString(), 'status' => 'present', 'time_in' => now()->subHour(),
        ]);

        return $user;
    }

    private function order(User $creator): MaintenanceWorkOrder
    {
        $machine = Machine::factory()->create(['status' => 'idle']);

        return MaintenanceWorkOrder::query()->create([
            'mwo_number' => 'ROLE-'.uniqid(),
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'type' => 'corrective',
            'priority' => 'high',
            'description' => 'Role workflow test',
            'status' => 'open',
            'created_by' => $creator->id,
        ]);
    }
}
