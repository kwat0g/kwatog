<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Middleware\SessionTimeout;
use App\Common\Services\SettingsService;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

class WorkOrderCrewTest extends TestCase
{
    use HasPassedInProcessQc;
    use RefreshDatabase;

    private function manager(): User
    {
        $role = Role::firstOrCreate(['slug' => 'production_manager'], ['name' => 'Production manager', 'is_system' => true]);
        foreach (['production.view', 'production.work_orders.view', 'production.wo.assign', 'production.wo.record', 'production.work_orders.lifecycle'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'production']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function worker(): User
    {
        $role = Role::firstOrCreate(['slug' => 'employee'], ['name' => 'Employee', 'is_system' => true]);
        $department = Department::firstOrCreate(['code' => 'PROD'], ['name' => 'Production', 'is_active' => true]);

        return User::factory()->create(['role_id' => $role->id, 'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id]);
    }

    public function test_multiple_workers_one_recorder_and_handover_keep_original_history(): void
    {
        $manager = $this->manager();
        $a = $this->worker();
        $b = $this->worker();
        $wo = WorkOrder::factory()->create(['status' => 'in_progress']);
        $this->liveSlot($wo, [$a, $b]);
        $service = app(WorkOrderAssignmentService::class);
        $data = ['employee_ids' => [$a->employee_id, $b->employee_id], 'recorder_employee_id' => $a->employee_id,
            'minimum_workers' => 2, 'maximum_workers' => 3, 'reason' => 'Initial production crew', 'expected_crew_id' => null];
        $first = $service->assign($wo, $data, $manager);
        $service->assertCanRecord($a, $wo, $first->id);
        $this->assertFalse($a->hasPermission('production.wo.record'));
        $this->actingAs($b, 'sanctum')->getJson('/api/v1/production/my-work-orders/'.$wo->hash_id)
            ->assertOk()->assertJsonPath('data.can_record', false);
        $second = $service->assign($wo, array_replace($data, ['recorder_employee_id' => $b->employee_id,
            'expected_crew_id' => $first->id, 'reason' => 'Recorder handover']), $manager);
        $this->assertNotNull($first->fresh()->ended_at);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($a->employee_id, $first->recorder_employee_id);
        $this->expectException(BusinessRuleException::class);
        $service->assertCanRecord($a, $wo, $first->id);
    }

    public function test_staffing_bounds_reject_invalid_crew_without_saving(): void
    {
        $manager = $this->manager();
        $a = $this->worker();
        $wo = WorkOrder::factory()->create();
        try {
            app(WorkOrderAssignmentService::class)->assign($wo, ['employee_ids' => [$a->employee_id],
                'recorder_employee_id' => $a->employee_id, 'minimum_workers' => 2, 'maximum_workers' => 3,
                'expected_crew_id' => null, 'reason' => 'Too few workers'], $manager);
            $this->fail('Crew below minimum must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('minimum', $e->getMessage());
        }
        $this->assertSame(0, $wo->crews()->count());
    }

    public function test_unassigned_employee_cannot_read_wo_or_general_production_list(): void
    {
        $employee = $this->worker();
        $wo = WorkOrder::factory()->create();
        $this->actingAs($employee, 'sanctum')->getJson('/api/v1/production/my-work-orders/'.$wo->hash_id)->assertNotFound();
        $this->getJson('/api/v1/production/work-orders')->assertForbidden();
    }

    public function test_worker_batch_output_uses_hr_shift_and_exact_replay_after_completion(): void
    {
        $this->seed([SettingsSeeder::class, ChartOfAccountsSeeder::class]);
        app(SettingsService::class)->set('modules.accounting', true, 'modules');

        $manager = $this->manager();
        $recorder = $this->worker();
        $support = $this->worker();
        $wo = WorkOrder::factory()->create(['status' => 'in_progress', 'quantity_target' => 50]);
        $warehouse = Warehouse::factory()->create();
        $zone = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouse->id,
            'zone_type' => WarehouseZoneType::FinishedGoods->value,
        ]);
        WarehouseLocation::factory()->create(['zone_id' => $zone->id, 'is_active' => true]);
        Item::factory()->create([
            'code' => $wo->product->part_number,
            'item_type' => ItemType::FinishedGood->value,
            'unit_of_measure' => $wo->product->unit_of_measure,
            'standard_cost' => $wo->product->standard_cost,
        ]);
        app(InspectionSpecService::class)->upsertForProduct($wo->product_id, [[
            'parameter_name' => 'Surface condition',
            'parameter_type' => 'visual',
        ]], $manager->id);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', config('app.timezone')));
        $this->passInProcessQc($wo);
        $shift = Shift::create(['name' => 'Day shift', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($recorder->employee_id, $shift->id, now()->toDateString(), null, [1, 2, 3, 4, 5, 6, 7]);
        $this->liveSlot($wo, [$recorder, $support]);
        $crew = app(WorkOrderAssignmentService::class)->assign($wo, ['employee_ids' => [$recorder->employee_id, $support->employee_id],
            'recorder_employee_id' => $recorder->employee_id, 'minimum_workers' => 1, 'maximum_workers' => 3,
            'expected_crew_id' => null, 'reason' => 'Assigned batch crew'], $manager);
        $defect = DefectType::create(['code' => 'CREW-DENT', 'name' => 'Dent', 'is_active' => true]);
        $data = ['crew_id' => $crew->hash_id, 'good_count' => 45, 'reject_count' => 5,
            'defects' => [['defect_type_id' => $defect->hash_id, 'count' => 5]]];
        $url = '/api/v1/production/my-work-orders/'.$wo->hash_id.'/outputs';
        $first = $this->actingAs($recorder, 'sanctum')->postJson($url, $data, ['X-Idempotency-Key' => 'crew-output-1'])->assertOk();
        $saved = $wo->outputs()->first();
        $this->assertSame('Day shift', $saved->shift);
        $this->assertCount(2, $saved->crew_snapshot['members']);
        $this->assertSame($recorder->id, $saved->recorded_by);
        $this->assertSame(45, (int) $wo->fresh()->quantity_good);
        $this->assertSame('in_progress', $wo->fresh()->status->value);
        $this->assertSame('generated', $saved->fresh()->production_receipt_handoff_status->value);
        $movement = StockMovement::query()->findOrFail($saved->production_receipt_movement_id);
        $this->assertSame('generated', $movement->gl_handoff_status->value);
        $this->assertNotNull($movement->journal_entry_id);
        $this->assertSame('posted', DB::table('journal_entries')->where('id', $movement->journal_entry_id)->value('status'));
        $this->assertSame('45.000', (string) DB::table('stock_levels')->where('item_id', $movement->item_id)->value('quantity'));
        $debits = (string) DB::table('journal_entry_lines')->where('journal_entry_id', $movement->journal_entry_id)->sum('debit');
        $credits = (string) DB::table('journal_entry_lines')->where('journal_entry_id', $movement->journal_entry_id)->sum('credit');
        $this->assertSame(0, bccomp($debits, $credits, 2));
        $this->actingAs($support, 'sanctum')->postJson($url, $data, ['X-Idempotency-Key' => 'support-new'])->assertUnprocessable();
        $this->postJson($url, $data, ['X-Idempotency-Key' => 'crew-output-1'])->assertUnprocessable();
        $auditCountBeforeComplete = DB::table('audit_logs')
            ->where('model_type', $wo->getMorphClass())
            ->where('model_id', $wo->id)
            ->where('action', 'updated')
            ->count();
        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/production/work-orders/'.$wo->hash_id.'/complete', ['short_completion_reason' => 'Five rejected pieces require shortage recovery after QC.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertNotNull($wo->fresh()->actual_end);
        $this->assertSame(1, DB::table('inspections')
            ->where('entity_type', 'work_order')
            ->where('entity_id', $wo->id)
            ->where('stage', 'outgoing')
            ->count());
        $this->actingAs($recorder, 'sanctum')->postJson($url, $data, ['X-Idempotency-Key' => 'crew-output-1'])
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson($url, $data, ['X-Idempotency-Key' => 'new-after-complete'])->assertUnprocessable();
        $this->assertSame(1, $wo->outputs()->count());
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $saved->id)
            ->count());
        $this->assertSame('45.000', (string) DB::table('stock_levels')->where('item_id', $movement->item_id)->value('quantity'));
        $this->assertSame(1, DB::table('event_outbox')
            ->where('event_type', WorkOrderCompleted::class)
            ->count());
        $this->assertSame($auditCountBeforeComplete + 2, DB::table('audit_logs')
            ->where('model_type', $wo->getMorphClass())
            ->where('model_id', $wo->id)
            ->where('action', 'updated')
            ->count());
    }

    public function test_http_assignment_bounds_and_recorder_membership_are_enforced(): void
    {
        $manager = $this->manager();
        $worker = $this->worker();
        $other = $this->worker();
        $wo = WorkOrder::factory()->create();
        $payload = ['employee_ids' => [$worker->employee->hash_id], 'recorder_employee_id' => $other->employee->hash_id,
            'minimum_workers' => 1, 'maximum_workers' => 2, 'expected_crew_id' => null, 'reason' => 'Not a member'];
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/production/work-orders/'.$wo->hash_id.'/crew', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/production/work-orders/'.$wo->hash_id.'/crew', array_replace($payload, ['maximum_workers' => 0]))->assertUnprocessable();
        $this->postJson('/api/v1/production/work-orders/'.$wo->hash_id.'/crew', array_replace($payload, ['minimum_workers' => 5, 'maximum_workers' => null]))->assertUnprocessable();
        $this->postJson('/api/v1/production/work-orders/'.$wo->hash_id.'/crew', array_replace($payload, ['maximum_workers' => 5]))->assertUnprocessable();
        $this->assertSame(0, $wo->crews()->count());
    }

    public function test_office_recorder_can_resolve_only_own_receipt_without_an_employee_link(): void
    {
        $manager = $this->manager();
        $worker = $this->worker();
        $wo = WorkOrder::factory()->create(['status' => 'completed']);
        $saved = $wo->outputs()->create(['recorded_by' => $manager->id, 'recorded_at' => now(), 'good_count' => 1,
            'reject_count' => 0, 'batch_code' => 'OFFICE-RECEIPT', 'idempotency_key' => 'office-lost-response']);
        $url = '/api/v1/production/my-output-receipts/'.$wo->hash_id.'?idempotency_key=office-lost-response';
        $this->actingAs($manager, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('data.id', $saved->hash_id);
        $this->actingAs($worker, 'sanctum')->getJson($url)->assertNotFound();
    }

    public function test_maximum_staffing_inactive_members_and_portal_accounts_are_rejected(): void
    {
        $manager = $this->manager();
        $a = $this->worker();
        $b = $this->worker();
        $wo = WorkOrder::factory()->create(['status' => 'in_progress']);
        $this->liveSlot($wo, [$a, $b]);
        $service = app(WorkOrderAssignmentService::class);
        $payload = ['employee_ids' => [$a->employee_id, $b->employee_id], 'recorder_employee_id' => $a->employee_id,
            'minimum_workers' => 1, 'maximum_workers' => 1, 'reason' => 'Maximum check', 'expected_crew_id' => null];
        try {
            $service->assign($wo, $payload, $manager);
            $this->fail('Crew above maximum must not save.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('maximum', $e->getMessage());
        }
        $crew = $service->assign($wo, array_replace($payload, ['maximum_workers' => 2]), $manager);
        $b->employee()->first()->forceFill(['status' => 'suspended'])->save();
        try {
            $service->assertCanRecord($a, $wo, $crew->id);
            $this->fail('Inactive crew must require a handover.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('eligibility', $e->getMessage());
        }
        $supplier = SupplierPortalUser::factory()->create();
        $this->actingAs($supplier, 'sanctum')->getJson('/api/v1/production/my-work-orders')->assertUnauthorized();
    }

    private function slot(WorkOrder $wo, string $start, string $end): ProductionSchedule
    {
        $machine = Machine::factory()->create(['available_hours_per_day' => 16]);
        $mold = Mold::create(['mold_code' => 'CREW-'.bin2hex(random_bytes(5)), 'name' => 'Crew scheduling mold',
            'product_id' => $wo->product_id, 'status' => 'available', 'cavity_count' => 1, 'cycle_time_seconds' => 10,
            'output_rate_per_hour' => 1000, 'setup_time_minutes' => 0, 'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000, 'lifetime_total_shots' => 0, 'lifetime_max_shots' => 1000000]);
        $mold->compatibleMachines()->attach($machine->id);

        return $wo->schedules()->create(['machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => $start, 'scheduled_end' => $end, 'priority_order' => 0, 'status' => 'pending', 'reserves_capacity' => true]);
    }

    private function liveSlot(WorkOrder $wo, array $workers): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', config('app.timezone')));
        $originalStatus = $wo->status;
        $wo->forceFill(['status' => 'planned'])->save();
        $slot = $this->slot($wo, now()->subMinute()->toDateTimeString(), now()->addHours(2)->toDateTimeString());
        $slot->update(['status' => 'confirmed', 'is_confirmed' => true, 'confirmed_at' => now()]);
        $wo->update(['machine_id' => $slot->machine_id, 'mold_id' => $slot->mold_id, 'status' => $originalStatus]);
        $slot->machine->update(['status' => 'running', 'current_work_order_id' => $wo->id]);
        $slot->mold->update(['status' => 'in_use', 'current_work_order_id' => $wo->id]);
        foreach ($workers as $worker) {
            if (! EmployeeShiftAssignment::where('employee_id', $worker->employee_id)->exists()) {
                $shift = Shift::firstOrCreate(['name' => 'Live full-day coverage'], ['start_time' => '00:00', 'end_time' => '00:00', 'is_active' => true]);
                app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, now()->subDay()->toDateString(), null, [1, 2, 3, 4, 5, 6, 7]);
            }
            $interval = app(WorkOrderAssignmentService::class)->shiftInterval($worker->employee, now());
            Attendance::create(['employee_id' => $worker->employee_id, 'date' => $interval['work_date'],
                'shift_id' => $interval['shift_id'], 'time_in' => $interval['start'], 'status' => 'present']);
        }
    }

    private function crewData(User $worker): array
    {
        return ['employee_ids' => [$worker->employee_id], 'recorder_employee_id' => $worker->employee_id,
            'minimum_workers' => 1, 'maximum_workers' => null, 'expected_crew_id' => null, 'reason' => 'Reviewed scheduled assignment'];
    }

    public function test_same_worker_can_hold_separate_slots_but_overlap_preserves_both_crews(): void
    {
        $worker = $this->worker();
        $manager = $this->manager();
        $a = WorkOrder::factory()->create();
        $b = WorkOrder::factory()->create();
        $this->slot($a, '2026-10-10 08:00:00', '2026-10-10 10:00:00');
        $slot = $this->slot($b, '2026-10-10 10:30:00', '2026-10-10 12:00:00');
        $shift = Shift::create(['name' => 'Separate future slots', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        $service = app(WorkOrderAssignmentService::class);
        $service->assign($a, $this->crewData($worker), $manager);
        $crew = $service->assign($b, $this->crewData($worker), $manager);
        $this->assertCount(2, WorkOrderCrew::query()->whereNull('ended_at')
            ->whereHas('members', fn ($q) => $q->where('employee_id', $worker->employee_id))->get());
        $this->assertFalse($worker->hasPermission('production.wo.record'));
        $slot->update(['scheduled_start' => '2026-10-10 09:00:00', 'scheduled_end' => '2026-10-10 11:00:00']);
        try {
            $service->assign($b, array_replace($this->crewData($worker), ['expected_crew_id' => $crew->id]), $manager);
            $this->fail('Overlapping crew must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('overlaps', $e->getMessage());
        }
        $this->assertNull($crew->fresh()->ended_at);
        $this->assertSame(1, $b->crews()->count());
    }

    public function test_manual_future_assignment_without_schedule_id_requires_hr_shift_coverage(): void
    {
        $worker = $this->worker();
        $wo = WorkOrder::factory()->create();
        $this->slot($wo, '2026-10-10 08:00:00', '2026-10-10 10:00:00');

        try {
            app(WorkOrderAssignmentService::class)->assign($wo, $this->crewData($worker), $this->manager());
            $this->fail('Manual crew assignment must require HR shift coverage for its canonical slot.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Worker availability changed', $e->getMessage());
        }

        $this->assertSame(0, $wo->crews()->count());
    }

    public function test_newly_approved_leave_blocks_start_after_assignment(): void
    {
        $worker = $this->worker();
        $wo = WorkOrder::factory()->create();
        $slot = $this->slot($wo, '2026-10-10 08:00:00', '2026-10-10 10:00:00');
        $shift = Shift::create(['name' => 'Leave recheck day', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        $service = app(WorkOrderAssignmentService::class);
        $service->assign($wo, $this->crewData($worker), $this->manager());
        $leave = LeaveRequest::factory()->create(['employee_id' => $worker->employee_id,
            'start_date' => '2026-10-10', 'end_date' => '2026-10-10']);
        $leave->forceFill(['status' => 'approved'])->save();

        try {
            $service->assertReadyForStart($wo, null, true);
            $this->fail('Newly approved leave must block start.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Worker availability changed', $e->getMessage());
        }
    }

    public function test_non_production_options_and_assignment_are_rejected(): void
    {
        $worker = $this->worker();
        $office = Department::firstOrCreate(['code' => 'OFFICE'], ['name' => 'Office', 'is_active' => true]);
        $worker->employee()->first()->forceFill(['department_id' => $office->id])->save();
        $wo = WorkOrder::factory()->create(['status' => 'in_progress']);
        $this->liveSlot($wo, [$worker]);
        $manager = $this->manager();
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/production/work-orders/'.$wo->hash_id.'/crew-options')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->expectException(BusinessRuleException::class);
        app(WorkOrderAssignmentService::class)->assign($wo, $this->crewData($worker), $manager);
    }

    public function test_suggestion_requires_shift_and_preserves_valid_crew_without_writes(): void
    {
        $worker = $this->worker();
        $wo = WorkOrder::factory()->create();
        $slot = $this->slot($wo, '2026-10-10 08:00:00', '2026-10-10 10:00:00');
        $service = app(WorkOrderAssignmentService::class);
        $blocked = $service->suggest($wo, $slot);
        $this->assertNotEmpty($blocked['blockers']);
        $this->assertStringNotContainsString('active internal recorder account', implode(' ', $blocked['staffing_blockers']));
        $this->assertFalse($blocked['worker_options'][0]['can_assign']);
        $this->assertSame('HR working days are missing. Ask HR to set a work calendar.', $blocked['worker_options'][0]['blocked_reason']);
        $shift = Shift::create(['name' => 'Scheduled day', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        $crew = $service->assign($wo, $this->crewData($worker), $this->manager());
        $result = $service->suggest($wo, $slot);
        $this->assertSame([], $result['blockers']);
        $this->assertSame($worker->employee->hash_id, $result['recorder_employee_id']);
        $this->assertSame($crew->hash_id, $result['expected_crew_id']);
        $this->assertSame(1, $wo->crews()->count());
        $this->assertNull($crew->fresh()->ended_at);
    }

    public function test_suggestion_staffing_overrides_are_read_only_and_select_the_requested_minimum(): void
    {
        $manager = $this->manager();
        $workers = [$this->worker(), $this->worker(), $this->worker()];
        $wo = WorkOrder::factory()->create(['minimum_workers' => 1, 'maximum_workers' => 2]);
        $slot = $this->slot($wo, '2026-10-10 08:00:00', '2026-10-10 10:00:00');
        $shift = Shift::create(['name' => 'Override coverage '.bin2hex(random_bytes(3)), 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        foreach ($workers as $worker) {
            app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        }
        $before = [$wo->minimum_workers, $wo->maximum_workers, $slot->id, $slot->updated_at?->toIso8601String()];

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/production/work-orders/'.$wo->hash_id
            .'/assignment-suggestion?minimum_workers=3&maximum_workers=4')->assertOk();

        $response->assertJsonPath('data.minimum_workers', 3)->assertJsonPath('data.maximum_workers', 4)
            ->assertJsonCount(3, 'data.members')->assertJsonCount(3, 'data.eligible_workers')
            ->assertJsonPath('data.staffing_blockers', []);
        $this->assertSame($before, [$wo->fresh()->minimum_workers, $wo->fresh()->maximum_workers,
            $wo->schedules()->first()->id, $wo->schedules()->first()->updated_at?->toIso8601String()]);
        $this->assertSame(0, $wo->crews()->count());
    }

    public function test_suggestion_staffing_override_reports_shortage_and_preserves_current_crew_in_range(): void
    {
        $manager = $this->manager();
        $workers = [$this->worker(), $this->worker()];
        $wo = WorkOrder::factory()->create(['minimum_workers' => 1, 'maximum_workers' => 3]);
        $slot = $this->slot($wo, '2026-10-10 08:00:00', '2026-10-10 10:00:00');
        $shift = Shift::create(['name' => 'Override preservation '.bin2hex(random_bytes(3)), 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        foreach ($workers as $worker) {
            app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        }
        $crew = app(WorkOrderAssignmentService::class)->assign($wo, [
            'employee_ids' => array_map(fn ($worker) => $worker->employee_id, $workers),
            'recorder_employee_id' => $workers[0]->employee_id, 'minimum_workers' => 2, 'maximum_workers' => 3,
            'expected_crew_id' => null, 'reason' => 'Manual crew inside reviewed range'], $manager);

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/production/work-orders/'.$wo->hash_id
            .'/assignment-suggestion?minimum_workers=0&maximum_workers=3')->assertUnprocessable();
        $this->getJson('/api/v1/production/work-orders/'.$wo->hash_id
            .'/assignment-suggestion?minimum_workers=3&maximum_workers=2')->assertUnprocessable();
        $this->getJson('/api/v1/production/work-orders/'.$wo->hash_id
            .'/assignment-suggestion?minimum_workers=5')->assertUnprocessable();
        $this->getJson('/api/v1/production/work-orders/'.$wo->hash_id
            .'/assignment-suggestion?maximum_workers=5')->assertUnprocessable();
        $shortage = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/production/work-orders/'.$wo->hash_id
            .'/assignment-suggestion?minimum_workers=3&maximum_workers=4')->assertOk();
        $shortage->assertJsonCount(2, 'data.eligible_workers')->assertJsonCount(2, 'data.members')
            ->assertJsonPath('data.staffing_blockers.0', fn ($blocker) => str_contains($blocker, 'Minimum staffing requires 3 workers'));

        $preserved = $this->getJson('/api/v1/production/work-orders/'.$wo->hash_id
            .'/assignment-suggestion?minimum_workers=1&maximum_workers=3')->assertOk();
        $this->assertSame($crew->hash_id, $preserved->json('data.expected_crew_id'));
        $this->assertEqualsCanonicalizing(
            collect($workers)->map(fn ($worker) => $worker->employee->hash_id)->all(),
            collect($preserved->json('data.members'))->pluck('id')->all(),
        );
        $this->assertNull($crew->fresh()->ended_at);
        $this->assertSame(2, $wo->fresh()->minimum_workers);
        $this->assertSame(3, $wo->fresh()->maximum_workers);
    }

    public function test_worker_options_explain_unavailability_and_include_all_production_employees(): void
    {
        $manager = $this->manager();
        $available = $this->worker();
        $noShift = $this->worker();
        $onLeave = $this->worker();
        $overlapping = $this->worker();
        $target = WorkOrder::factory()->create();
        $this->slot($target, '2026-10-10 08:00:00', '2026-10-10 10:00:00');
        $other = WorkOrder::factory()->create();
        $this->slot($other, '2026-10-10 09:00:00', '2026-10-10 11:00:00');
        $shift = Shift::create(['name' => 'Worker option coverage '.bin2hex(random_bytes(3)), 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        foreach ([$available, $onLeave, $overlapping] as $worker) {
            app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        }
        $leave = LeaveRequest::factory()->create(['employee_id' => $onLeave->employee_id,
            'start_date' => '2026-10-10', 'end_date' => '2026-10-10']);
        $leave->forceFill(['status' => 'approved'])->save();
        app(WorkOrderAssignmentService::class)->assign($other, $this->crewData($overlapping), $manager);

        $suggestion = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/production/work-orders/'.$target->hash_id
            .'/assignment-suggestion')->assertOk();
        $suggestionOptions = collect($suggestion->json('data.worker_options'))->keyBy('id');
        $this->assertCount(4, $suggestionOptions);
        $this->assertCount(1, $suggestion->json('data.eligible_workers'));
        $this->assertTrue($suggestionOptions[$available->employee->hash_id]['can_assign']);
        $this->assertFalse($suggestionOptions[$noShift->employee->hash_id]['can_assign']);
        $this->assertSame('HR working days are missing. Ask HR to set a work calendar.', $suggestionOptions[$noShift->employee->hash_id]['blocked_reason']);
        $this->assertFalse($suggestionOptions[$onLeave->employee->hash_id]['can_assign']);
        $this->assertStringContainsString('Approved leave from 2026-10-10 to 2026-10-10', $suggestionOptions[$onLeave->employee->hash_id]['blocked_reason']);
        $this->assertFalse($suggestionOptions[$overlapping->employee->hash_id]['can_assign']);
        $this->assertStringContainsString('overlaps '.$other->wo_number, $suggestionOptions[$overlapping->employee->hash_id]['blocked_reason']);

        $options = $this->getJson('/api/v1/production/work-orders/'.$target->hash_id.'/crew-options')->assertOk();
        $this->assertSame($suggestionOptions->pluck('can_assign')->all(), collect($options->json('data'))->keyBy('id')
            ->only($suggestionOptions->keys())->pluck('can_assign')->all());
        $office = Department::firstOrCreate(['code' => 'OFFICE'], ['name' => 'Office', 'is_active' => true]);
        $officeWorker = Employee::factory()->create(['department_id' => $office->id]);
        $this->assertArrayNotHasKey($officeWorker->hash_id, collect($options->json('data'))->keyBy('id')->all());

        $this->postJson('/api/v1/production/work-orders/'.$target->hash_id.'/crew', [
            'employee_ids' => [$noShift->employee->hash_id], 'recorder_employee_id' => $noShift->employee->hash_id,
            'minimum_workers' => 1, 'maximum_workers' => null, 'expected_crew_id' => null, 'reason' => 'Unavailable worker',
        ])->assertUnprocessable();
        $this->assertSame(0, $target->crews()->count());

        for ($i = 0; $i < 48; $i++) {
            $this->worker();
        }
        $allOptions = $this->getJson('/api/v1/production/work-orders/'.$target->hash_id.'/crew-options')->assertOk();
        $this->assertCount(52, $allOptions->json('data'));
        $this->assertArrayNotHasKey($officeWorker->hash_id, collect($allOptions->json('data'))->keyBy('id')->all());
    }

    public function test_only_employee_accounts_can_be_suggested_or_assigned_as_production_workers(): void
    {
        $worker = $this->worker();
        $manager = $this->manager();
        $manager->forceFill(['employee_id' => Employee::factory()->create([
            'department_id' => $worker->employee->department_id,
        ])->id])->save();
        $inactive = $this->worker();
        $inactive->forceFill(['is_active' => false])->save();
        $target = WorkOrder::factory()->create();
        $this->slot($target, '2026-10-10 08:00:00', '2026-10-10 10:00:00');
        $shift = Shift::create(['name' => 'Employee-only coverage', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        foreach ([$worker, $manager, $inactive] as $user) {
            app(ShiftAssignmentService::class)->assignToEmployee($user->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        }
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/production/work-orders/'.$target->hash_id.'/crew-options')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $worker->employee->hash_id);
        $this->getJson('/api/v1/production/work-orders/'.$target->hash_id.'/assignment-suggestion')
            ->assertOk()->assertJsonCount(1, 'data.worker_options')->assertJsonCount(1, 'data.members')
            ->assertJsonPath('data.members.0.id', $worker->employee->hash_id);
        $this->postJson('/api/v1/production/work-orders/'.$target->hash_id.'/crew', [
            'employee_ids' => [$worker->employee->hash_id, $manager->employee->hash_id],
            'recorder_employee_id' => $worker->employee->hash_id,
            'minimum_workers' => 2, 'maximum_workers' => 2, 'expected_crew_id' => null,
            'reason' => 'Tampered manager assignment',
        ])->assertUnprocessable();
        $this->assertSame(0, $target->crews()->count());
        $this->assertFalse(app(WorkOrderAssignmentService::class)->employeeOption($manager->employee)['has_active_account']);
    }

    public function test_runtime_overrun_and_department_transfer_block_output_readiness(): void
    {
        $worker = $this->worker();
        $manager = $this->manager();
        $a = WorkOrder::factory()->create(['status' => 'in_progress']);
        $this->liveSlot($a, [$worker]);
        $b = WorkOrder::factory()->create();
        $service = app(WorkOrderAssignmentService::class);
        $crew = $service->assign($a, $this->crewData($worker), $manager);
        $this->slot($b, '2026-10-10 13:00:00', '2026-10-10 15:00:00');
        $this->travelTo(Carbon::parse('2026-10-10 12:01:00', config('app.timezone')));
        try {
            $service->assign($b, $this->crewData($worker), $manager);
            $this->fail('Unscheduled runtime responsibility must block a future crew.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('executing', $e->getMessage());
        }
        $worker->employee->department->update(['is_active' => false]);
        $this->expectException(BusinessRuleException::class);
        $service->assertReadyForStart($a);
    }

    public function test_overnight_shift_covers_next_date_and_approved_leave_excludes_worker(): void
    {
        $worker = $this->worker();
        $wo = WorkOrder::factory()->create();
        $slot = $this->slot($wo, '2026-10-10 23:00:00', '2026-10-11 02:00:00');
        $shift = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'is_night_shift' => true, 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        $service = app(WorkOrderAssignmentService::class);
        $this->assertCount(1, $service->eligibleWorkers($wo, $slot->scheduled_start, $slot->scheduled_end));
        $leave = LeaveRequest::factory()->create(['employee_id' => $worker->employee_id,
            'start_date' => '2026-10-11', 'end_date' => '2026-10-11']);
        $leave->forceFill(['status' => 'approved'])->save();
        $this->assertCount(0, $service->eligibleWorkers($wo, $slot->scheduled_start, $slot->scheduled_end));
    }

    public function test_missing_slot_suggestion_read_is_pure_and_assign_permission_cannot_confirm(): void
    {
        $manager = $this->manager();
        $wo = WorkOrder::factory()->create();
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/production/work-orders/'.$wo->hash_id.'/assignment-suggestion')
            ->assertOk()->assertJsonPath('data.schedule', null);
        $this->assertSame(0, $wo->schedules()->count());
        $worker = $this->worker();
        $slot = $this->slot($wo, '2026-10-10 08:00:00', '2026-10-10 10:00:00');
        $payload = $this->crewData($worker);
        $payload['employee_ids'] = [$worker->employee->hash_id];
        $payload['recorder_employee_id'] = $worker->employee->hash_id;
        $payload['schedule_id'] = $slot->hash_id;
        $payload['schedule_updated_at'] = $slot->updated_at->toIso8601String();
        $this->postJson('/api/v1/production/work-orders/'.$wo->hash_id.'/assignment-apply', $payload)->assertForbidden();
        $this->assertSame('planned', $wo->fresh()->status->value);
        $this->assertSame(0, $wo->crews()->count());
    }

    public function test_proposal_day_is_validated_and_never_saved_until_confirmation(): void
    {
        $manager = $this->manager();
        $permission = Permission::firstOrCreate(['slug' => 'production.wo.confirm'], ['name' => 'Confirm', 'module' => 'production']);
        $manager->role->permissions()->syncWithoutDetaching([$permission->id]);
        $wo = WorkOrder::factory()->create(['planned_start' => now()->addDay(), 'planned_end' => now()->addDays(5)]);
        $worker = $this->worker();
        $shift = Shift::create(['name' => 'Proposal crew', 'start_time' => '00:00', 'end_time' => '00:00', 'is_night_shift' => true, 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, now()->subDay()->toDateString(), null, [1, 2, 3, 4, 5, 6, 7]);
        $this->slot($wo, now()->addDay()->startOfDay()->toDateTimeString(), now()->addDay()->startOfDay()->addHours(2)->toDateTimeString());
        $url = '/api/v1/production/work-orders/'.$wo->hash_id.'/assignment-suggestion';
        $this->actingAs($manager, 'sanctum');
        $this->postJson($url, ['preferred_production_date' => now()->subDay()->toDateString()])->assertUnprocessable();
        $day = now()->addDays(2)->toDateString();
        $response = $this->postJson($url, ['minimum_workers' => 1, 'maximum_workers' => 1, 'preferred_production_date' => $day])->assertOk();
        $this->assertStringStartsWith($day, $response->json('data.schedule.scheduled_start'));
        $response->assertJsonPath('data.preferred_production_date', $day);
        $this->assertNull($wo->fresh()->preferred_production_date);
        $this->assertSame(0, $wo->crews()->count());
        $slot = $wo->schedules()->where('reserves_capacity', true)->firstOrFail();
        $snapshot = $slot->getRawOriginal();
        $this->postJson($url, ['minimum_workers' => 1, 'maximum_workers' => 1,
            'preferred_production_date' => $wo->planned_end->copy()->addDay()->toDateString()])
            ->assertOk()->assertJsonPath('data.schedule', null)
            ->assertJsonPath('data.equipment_blockers.0', 'Choose a day before production due: '.$wo->planned_end->format('M j, Y · g:i A').'.');
        $this->assertSame($snapshot, $slot->fresh()->getRawOriginal());
        $this->assertNull($wo->fresh()->preferred_production_date);
        $wo->update(['planned_end' => $wo->planned_end->copy()->startOfDay()]);
        $this->postJson($url, ['minimum_workers' => 1, 'maximum_workers' => 1,
            'preferred_production_date' => $wo->planned_end->toDateString()])
            ->assertOk()->assertJsonPath('data.schedule', null)
            ->assertJsonPath('data.equipment_blockers.0', 'Choose a day before production due: '.$wo->planned_end->format('M j, Y · g:i A').'.');
        $this->assertSame($snapshot, $slot->fresh()->getRawOriginal());
    }

    public function test_assign_only_actor_cannot_generate_new_date_proposal(): void
    {
        $wo = WorkOrder::factory()->create();
        $this->actingAs($this->manager(), 'sanctum')->postJson(
            '/api/v1/production/work-orders/'.$wo->hash_id.'/assignment-suggestion',
            ['preferred_production_date' => now()->toDateString()]
        )->assertForbidden();
    }

    public function test_support_without_employee_account_is_excluded_from_suggestions(): void
    {
        $worker = $this->worker();
        $department = $worker->employee->department;
        $support = Employee::factory()->create(['department_id' => $department->id]);
        $wo = WorkOrder::factory()->create(['minimum_workers' => 2]);
        $slot = $this->slot($wo, '2026-10-10 08:00:00', '2026-10-10 10:00:00');
        $shift = Shift::create(['name' => 'Crew day', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        foreach ([$worker->employee_id, $support->id] as $id) {
            app(ShiftAssignmentService::class)->assignToEmployee($id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        }
        $service = app(WorkOrderAssignmentService::class);
        $suggestion = $service->suggest($wo, $slot);
        $this->assertCount(1, $suggestion['members']);
        $this->assertCount(1, $suggestion['worker_options']);
        $this->assertSame($worker->employee->hash_id, $suggestion['members'][0]['id']);
        $this->assertNotEmpty($suggestion['blockers']);
        $worker->forceFill(['is_active' => false])->save();
        $blocked = $service->suggest($wo->fresh(), $slot);
        $this->assertNotEmpty($blocked['blockers']);
        $this->assertNull($blocked['recorder_employee_id']);
        $this->assertSame(0, $wo->crews()->count());
    }

    public function test_future_nonoverlap_membership_does_not_revoke_runtime_output(): void
    {
        $worker = $this->worker();
        $manager = $this->manager();
        $live = WorkOrder::factory()->create();
        $future = WorkOrder::factory()->create();
        $this->slot($live, '2026-10-10 08:00:00', '2026-10-10 10:00:00');
        $this->slot($future, '2026-10-10 10:00:00', '2026-10-10 12:00:00');
        $shift = Shift::create(['name' => 'Live and future coverage', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        $service = app(WorkOrderAssignmentService::class);
        $crew = $service->assign($live, $this->crewData($worker), $manager);
        $service->assign($future, $this->crewData($worker), $manager);
        $live->forceFill(['status' => 'in_progress'])->save();
        $this->assertSame($crew->id, $service->assertReadyForStart($live)->id);
        $this->expectException(BusinessRuleException::class);
        $service->assertReadyForStart($future, null, true);
    }

    public function test_unknown_presence_requires_authorized_action_attestation_and_never_overrides_absence(): void
    {
        $manager = $this->manager();
        $worker = $this->worker();
        $wo = WorkOrder::factory()->create(['status' => 'in_progress']);
        $this->liveSlot($wo, [$worker]);
        $attendance = Attendance::where('employee_id', $worker->employee_id)->firstOrFail();
        $attendance->delete();
        $service = app(WorkOrderAssignmentService::class);
        $employees = collect([$worker->employee]);
        try {
            $service->assertPresence($wo, $employees, $manager, [$worker->employee_id]);
            $this->fail('Archived attendance requires HR review, not presence attestation.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('archived', $error->getMessage());
        }
        $attendance->forceDelete();
        try {
            $service->assertPresence($wo, $employees, $manager);
            $this->fail('Unknown attendance requires attestation.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('physical presence', $error->getMessage());
        }
        $service->assertPresence($wo, $employees, $manager, [$worker->employee_id]);
        $this->assertDatabaseHas('activity_events', ['action' => 'presence_confirmed', 'actor_user_id' => $manager->id]);
        try {
            $service->assertPresence($wo, $employees, $worker, [$worker->employee_id]);
            $this->fail('Worker cannot attest managerial presence.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        Attendance::create(['employee_id' => $worker->employee_id, 'date' => $attendance->date,
            'shift_id' => $attendance->shift_id, 'status' => 'absent']);
        try {
            $service->assertPresence($wo, $employees, $manager, [$worker->employee_id]);
            $this->fail('Known absence must not be overridden.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('absent', $error->getMessage());
        }
    }

    public function test_reviewing_time_and_replacement_crew_commits_together_and_rolls_back_invalid_recorder(): void
    {
        $manager = $this->manager();
        $oldWorker = $this->worker();
        $replacement = $this->worker();
        $wo = WorkOrder::factory()->create(['status' => 'confirmed', 'quantity_target' => 50, 'planned_end' => '2026-10-11 17:00:00']);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', config('app.timezone')));
        $oldSlot = $this->slot($wo, '2026-10-10 10:00:00', '2026-10-10 12:00:00');
        $oldSlot->update(['status' => 'confirmed', 'is_confirmed' => true]);
        $wo->update(['machine_id' => $oldSlot->machine_id, 'mold_id' => $oldSlot->mold_id]);
        $shift = Shift::create(['name' => 'Recovery day', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        foreach ([$oldWorker, $replacement] as $worker) {
            app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        }
        $crewService = app(WorkOrderAssignmentService::class);
        $oldCrew = $crewService->assign($wo, $this->crewData($oldWorker), $manager);
        $start = now()->addHours(3);
        $end = $start->copy()->addHour();
        $review = ['expected_schedule_id' => $oldSlot->id, 'expected_schedule_updated_at' => $oldSlot->updated_at->toISOString(),
            'scheduled_start' => $start->toISOString(), 'scheduled_end' => $end->toISOString(),
            'employee_ids' => [$replacement->employee_id], 'recorder_employee_id' => $oldWorker->employee_id,
            'expected_crew_id' => $oldCrew->id, 'preferred_production_date' => $start->toDateString(), 'reason' => 'Replan delayed production.'];
        $service = app(WorkOrderService::class);
        try {
            $service->reviewSlot($wo, $start, $end, $manager->id, $review['reason'], $review);
            $this->fail('Invalid recorder must not save replacement time.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('recorder', $error->getMessage());
        }
        $this->assertTrue($oldSlot->fresh()->reserves_capacity);
        $this->assertNull($wo->fresh()->preferred_production_date);
        $this->assertNull($oldCrew->fresh()->ended_at);
        $review['recorder_employee_id'] = $replacement->employee_id;
        Holiday::create(['name' => 'Recovery holiday', 'date' => $start->toDateString(), 'type' => 'regular']);
        try {
            $service->reviewSlot($wo, $start, $end, $manager->id, $review['reason'], $review);
            $this->fail('Holiday timing needs explicit authorization.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Holiday production', $error->getMessage());
        }
        $this->assertNull($oldCrew->fresh()->ended_at);
        $review['holiday_authorized'] = true;
        $service->reviewSlot($wo, $start, $end, $manager->id, $review['reason'], $review);
        $this->assertFalse($oldSlot->fresh()->reserves_capacity);
        $this->assertSame($start->toDateString(), $wo->fresh()->preferred_production_date->toDateString());
        $this->assertNotNull($oldCrew->fresh()->ended_at);
        $this->assertSame($replacement->employee_id, $crewService->currentCrew($wo)->recorder_employee_id);
        $this->assertTrue($crewService->schedule($wo)->scheduled_start->equalTo($start));
        $savedCrew = $crewService->currentCrew($wo)->id;
        $savedSlot = $crewService->schedule($wo)->id;
        $this->assertTrue($crewService->holidayAuthorized($crewService->schedule($wo)));
        $service->reviewSlot($wo, $start, $end, $manager->id, $review['reason'], $review);
        $this->assertSame($savedCrew, $crewService->currentCrew($wo)->id);
        $this->assertSame($savedSlot, $crewService->schedule($wo)->id);
    }

    public function test_confirmation_saves_preference_atomically_and_replays_exact_assignment(): void
    {
        $manager = $this->manager();
        $permission = Permission::firstOrCreate(['slug' => 'production.wo.confirm'], ['name' => 'Confirm', 'module' => 'production']);
        $manager->role->permissions()->syncWithoutDetaching([$permission->id]);
        $worker = $this->worker();
        $this->travelTo(Carbon::parse('2026-10-10 07:00:00', config('app.timezone')));
        $wo = WorkOrder::factory()->create(['status' => 'planned', 'quantity_target' => 50, 'planned_end' => '2026-10-12 17:00:00']);
        $slot = $this->slot($wo, '2026-10-11 08:00:00', '2026-10-11 10:00:00');
        $shift = Shift::create(['name' => 'Confirmation shift', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        $item = Item::factory()->create();
        $material = $wo->materials()->create(['item_id' => $item->id, 'bom_quantity' => 1, 'actual_quantity_issued' => 0]);
        $payload = ['employee_ids' => [$worker->employee->hash_id], 'recorder_employee_id' => $worker->employee->hash_id,
            'minimum_workers' => 1, 'maximum_workers' => 1, 'expected_crew_id' => null,
            'schedule_id' => $slot->hash_id, 'schedule_updated_at' => $slot->updated_at->toISOString(),
            'preferred_production_date' => '2026-10-11', 'reason' => 'Initial automatic crew assignment'];
        $url = '/api/v1/production/work-orders/'.$wo->hash_id.'/assignment-apply';
        $this->actingAs($manager, 'sanctum')->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame('planned', $wo->fresh()->status->value);
        $this->assertNull($wo->fresh()->preferred_production_date);
        $this->assertSame(0, $wo->crews()->count());
        $material->update(['bom_quantity' => 0]);
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame('2026-10-11', $wo->fresh()->preferred_production_date->toDateString());
        $this->assertSame('confirmed', $wo->fresh()->status->value);
        $crewId = app(WorkOrderAssignmentService::class)->currentCrew($wo)->id;
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame($crewId, app(WorkOrderAssignmentService::class)->currentCrew($wo)->id);
        $this->assertNull($wo->fresh()->actual_start);
    }

    public function test_expired_short_and_stale_confirmation_preserve_reviewed_business_state(): void
    {
        $this->withoutMiddleware(SessionTimeout::class);
        $manager = $this->manager();
        $permission = Permission::firstOrCreate(['slug' => 'production.wo.confirm'], ['name' => 'Confirm', 'module' => 'production']);
        $manager->role->permissions()->syncWithoutDetaching([$permission->id]);
        $worker = $this->worker();
        $this->travelTo(Carbon::parse('2026-10-10 08:00:00', config('app.timezone')));
        $wo = WorkOrder::factory()->create(['status' => 'planned', 'quantity_target' => 50, 'planned_end' => '2026-10-12 17:00:00']);
        $slot = $this->slot($wo, '2026-10-10 08:00:00', '2026-10-10 10:00:00');
        $shift = Shift::create(['name' => 'Confirmation recovery', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        $wo->materials()->create(['item_id' => Item::factory()->create()->id, 'bom_quantity' => 0, 'actual_quantity_issued' => 0]);
        $payload = ['employee_ids' => [$worker->employee->hash_id], 'recorder_employee_id' => $worker->employee->hash_id,
            'minimum_workers' => 1, 'maximum_workers' => 1, 'expected_crew_id' => null,
            'schedule_id' => $slot->hash_id, 'schedule_updated_at' => $slot->updated_at->toISOString(),
            'preferred_production_date' => '2026-10-10', 'reason' => 'Initial automatic crew assignment'];
        $woBefore = $wo->fresh()->getRawOriginal();
        $slotBefore = $slot->fresh()->getRawOriginal();
        $reservations = DB::table('material_reservations')->count();
        $this->actingAs($manager, 'sanctum');
        foreach ([['09:59:00', $payload, 'Proposal no longer fits. Find a new slot before confirming.'],
            ['10:00:00', $payload, 'Proposal passed. Find a new slot before confirming.'],
            ['08:00:00', array_replace($payload, ['schedule_updated_at' => now()->subHour()->toISOString()]),
                'The schedule changed. Refresh and review the proposal before applying.']] as [$time, $request, $message]) {
            $this->travelTo(Carbon::parse('2026-10-10 '.$time, config('app.timezone')));
            $this->postJson('/api/v1/production/work-orders/'.$wo->hash_id.'/assignment-apply', $request)
                ->assertUnprocessable()->assertJsonPath('message', $message);
            $this->assertSame($woBefore, $wo->fresh()->getRawOriginal());
            $this->assertSame($slotBefore, $slot->fresh()->getRawOriginal());
            $this->assertSame(0, $wo->crews()->count());
            $this->assertSame($reservations, DB::table('material_reservations')->count());
        }
    }

    public function test_presence_distinguishes_unknown_absent_and_closed_attendance(): void
    {
        $worker = $this->worker();
        $shift = Shift::create(['name' => 'Presence day', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [1, 2, 3, 4, 5, 6, 7]);
        $at = Carbon::parse('2026-10-10 10:00:00', config('app.timezone'));
        $service = app(WorkOrderAssignmentService::class);
        $this->assertSame('unknown', $service->presence($worker->employee, $at)['state']);
        $attendance = Attendance::create(['employee_id' => $worker->employee_id, 'date' => '2026-10-10', 'shift_id' => $shift->id, 'status' => 'absent']);
        $this->assertSame('blocked', $service->presence($worker->employee, $at)['state']);
        $attendance->update(['status' => 'present', 'time_in' => $at->copy()->setTime(8, 0), 'time_out' => $at]);
        $this->assertSame('blocked', $service->presence($worker->employee, $at)['state']);
        $attendance->update(['time_out' => null]);
        $this->assertSame('present', $service->presence($worker->employee, $at)['state']);
        $attendance->update(['time_in' => $at->copy()->addMinute()]);
        $this->assertSame('blocked', $service->presence($worker->employee, $at)['state']);
    }

    public function test_night_presence_matches_preceding_work_date_and_explicit_sunday_working_day(): void
    {
        $worker = $this->worker();
        $shift = Shift::create(['name' => 'Sunday night', 'start_time' => '22:00', 'end_time' => '06:00', 'is_night_shift' => true, 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($worker->employee_id, $shift->id, '2026-10-01', null, [7]);
        $at = Carbon::parse('2026-10-12 03:06:00', config('app.timezone'));
        Attendance::create(['employee_id' => $worker->employee_id, 'date' => '2026-10-11', 'shift_id' => $shift->id,
            'time_in' => Carbon::parse('2026-10-11 22:00:00', config('app.timezone')), 'status' => 'rest_day', 'is_rest_day' => true]);
        $service = app(WorkOrderAssignmentService::class);
        $presence = $service->presence($worker->employee, $at);
        $this->assertSame('present', $presence['state']);
        $this->assertSame('2026-10-11', $presence['work_date']);
        EmployeeShiftAssignment::where('employee_id', $worker->employee_id)->firstOrFail()->update(['working_days' => [1, 2, 3, 4, 5, 6]]);
        $this->assertSame('blocked', $service->presence($worker->employee, $at)['state']);
    }
}
