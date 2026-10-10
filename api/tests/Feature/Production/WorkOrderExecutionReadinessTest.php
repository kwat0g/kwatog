<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\CapacityPlanningService;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\ProductionResourceService;
use App\Modules\Production\Services\WoOperationService;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\HasAssignedProductionCrew;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

class WorkOrderExecutionReadinessTest extends TestCase
{
    use HasAssignedProductionCrew;
    use HasPassedInProcessQc;
    use RefreshDatabase;

    private WorkOrder $wo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-06 08:00:00', config('app.timezone')));
        $manager = User::factory()->withRole('production_manager')->create(['is_active' => true]);
        $this->actingAs($manager);
        $this->wo = WorkOrder::factory()->create(['status' => 'confirmed', 'quantity_target' => 50,
            'planned_start' => now(), 'planned_end' => now()->addDays(2),
            'work_order_class' => 'non_stock', 'exception_reason' => 'Readiness regression.', 'exception_authorized_by' => $manager->id, 'created_by' => $manager->id]);
        $machine = Machine::factory()->create(['status' => 'idle', 'available_hours_per_day' => 8]);
        $mold = Mold::create(['product_id' => $this->wo->product_id, 'mold_code' => 'READY-MOLD', 'name' => 'Readiness mold',
            'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'setup_time_minutes' => 10,
            'current_shot_count' => 0, 'max_shots_before_maintenance' => 10000, 'lifetime_max_shots' => 100000, 'status' => 'available']);
        $mold->compatibleMachines()->sync([$machine->id]);
        $this->wo->update(['machine_id' => $machine->id, 'mold_id' => $mold->id]);
        ProductionSchedule::create(['work_order_id' => $this->wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now(), 'scheduled_end' => now()->addHours(2), 'priority_order' => 0,
            'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true]);
        $this->assignProductionCrew($this->wo, $manager);
        app(InspectionSpecService::class)->upsertForProduct($this->wo->product_id,
            [['parameter_name' => 'Appearance', 'parameter_type' => 'visual', 'is_critical' => false]], $manager->id);
    }

    private function snapshot(): array
    {
        return [$this->wo->fresh()->getRawOriginal(), $this->wo->machine->fresh()->getRawOriginal(),
            $this->wo->mold->fresh()->getRawOriginal(), $this->wo->schedules()->get()->toArray(),
            DB::table('stock_movements')->count(), DB::table('activity_events')->count(), DB::table('work_order_crews')->count()];
    }

    public function test_scheduler_snapshot_loads_execution_equipment_with_multiple_work_orders(): void
    {
        $second = $this->wo->replicate(['wo_number']);
        $second->wo_number = 'SNAPSHOT-SECOND';
        $second->save();
        $slot = $this->wo->schedules()->firstOrFail()->replicate();
        $slot->work_order_id = $second->id;
        $slot->scheduled_start = now()->addHours(2);
        $slot->scheduled_end = now()->addHours(4);
        $slot->save();
        $before = $this->snapshot();

        $this->getJson('/api/v1/mrp/scheduler/snapshot?from='.now()->toDateString().'&to='.now()->addDay()->toDateString())
            ->assertOk()->assertJsonCount(2, 'data.rows.0.bars')
            ->assertJsonStructure(['data' => ['rows' => [['bars' => [['staffing' => ['execution_blockers']]]]]]]);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_start_check_is_read_only_and_missing_attendance_requires_confirmation(): void
    {
        DB::table('attendances')->delete();
        $before = $this->snapshot();
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.ready', true)->assertJsonPath('data.needs_reschedule', false)
            ->assertJsonPath('data.presence.0.state', 'unknown');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_legacy_visual_spec_missing_pass_requirement_blocks_readiness_and_start(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $item = InspectionSpecItem::where('inspection_spec_id', $this->wo->product->inspectionSpec->id)->firstOrFail();
        $before = $this->snapshot();
        $execution = ['expected_crew_id' => app(WorkOrderAssignmentService::class)->currentCrew($this->wo)->hash_id];
        $response = $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.ready', false)->assertJsonPath('data.needs_reschedule', false);
        $message = collect($response->json('data.blockers'))->firstWhere('code', 'quality')['message'];
        $this->assertStringContainsString('Appearance', $message);
        $this->assertStringContainsString('PASS requirement', $message);
        $this->assertSame($before, $this->snapshot());
        $this->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/start', $execution)
            ->assertUnprocessable()->assertJsonPath('message', $message);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, Inspection::count());

        app(InspectionSpecService::class)->upsertForProduct($this->wo->product_id, [[
            'parameter_name' => 'Appearance', 'parameter_type' => 'visual', 'is_critical' => false,
            'acceptance_criterion' => 'No cracks, damage or contamination.',
        ]], $this->wo->created_by, 'Complete the missing visual requirement.', 'checklist');
        $this->assertNull($item->fresh()->acceptance_criterion);
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.ready', true);
        $this->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/start', $execution)
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $inspection = Inspection::firstOrFail();
        $this->assertSame('checklist', $inspection->capture_method);
        $this->assertSame('No cracks, damage or contamination.', $inspection->measurements->first()->acceptance_criterion);
        $this->assertSame(1, Inspection::count());
    }

    public function test_future_slot_offers_a_read_only_suggestion_without_requiring_manual_dates(): void
    {
        $slot = $this->wo->schedules()->firstOrFail();
        $slot->update(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(2)]);
        $before = $this->snapshot();
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.ready', true)->assertJsonPath('data.start_mode', 'early')->assertJsonPath('data.needs_reschedule', false);
        $result = $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/reschedule-suggestion')
            ->assertOk()->assertJsonCount(0, 'data.blockers')->assertJsonCount(1, 'data.members')
            ->assertJsonPath('data.expected_schedule_id', $slot->hash_id)->json('data');
        $this->assertTrue(Carbon::parse($result['scheduled_start'])->gte(now()->startOfMinute()));
        $this->assertTrue(Carbon::parse($result['scheduled_end'])->lte($slot->scheduled_end));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_future_schedule_still_revalidates_duration_and_crew_before_waiting(): void
    {
        $slot = $this->wo->schedules()->firstOrFail();
        $slot->update(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHour()->addMinutes(5)]);
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.ready', false)->assertJsonPath('data.needs_reschedule', true);
        $slot->update(['scheduled_end' => now()->addHours(2)]);
        DB::table('employee_shift_assignments')->update(['working_days' => json_encode([7])]);
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.ready', false)->assertJsonPath('data.needs_reschedule', false);
        $this->assertNull($this->wo->fresh()->actual_start);
    }

    public function test_preferred_day_search_uses_plant_calendar_and_offers_next_available_day_read_only(): void
    {
        DB::table('employee_shift_assignments')->update(['working_days' => json_encode([1, 2, 3, 4, 5, 6])]);
        $before = $this->snapshot();
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/reschedule-suggestion';
        $result = $this->getJson($url.'?preferred_date=2026-10-07')->assertOk()
            ->assertJsonCount(0, 'data.blockers')->json('data');
        $this->assertSame('2026-10-07', Carbon::parse($result['scheduled_start'])->setTimezone(config('app.timezone'))->toDateString());
        $this->assertEmpty($result['preferred_day_message'] ?? null);
        $this->assertSame($before, $this->snapshot());
        $this->wo->update(['planned_end' => now()->addDays(8)]);
        $before = $this->snapshot();
        $result = $this->getJson($url.'?preferred_date=2026-10-11')->assertOk()
            ->assertJsonCount(0, 'data.blockers')->json('data');
        $this->assertSame('2026-10-12', Carbon::parse($result['scheduled_start'])->setTimezone(config('app.timezone'))->toDateString());
        $this->assertNotEmpty($result['preferred_day_message']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_preferred_day_rejects_invalid_past_or_excessively_distant_dates(): void
    {
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/reschedule-suggestion';
        $before = $this->snapshot();
        foreach (['2026-10-05', '2026-02-30', 'invalid', '2027-02-01'] as $date) {
            $this->getJson($url.'?preferred_date='.$date)->assertUnprocessable()->assertJsonValidationErrors('preferred_date');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_material_and_quality_blockers_do_not_claim_resources_or_start_work(): void
    {
        $this->wo->update(['work_order_class' => 'standard']);
        DB::table('inspection_specs')->where('product_id', $this->wo->product_id)->update(['is_active' => false]);
        $before = $this->snapshot();
        $result = $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.ready', false)->json('data.blockers');
        $this->assertContains('material', array_column($result, 'code'));
        $this->assertContains('quality', array_column($result, 'code'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_employee_cannot_check_management_readiness_or_approve_suggestions(): void
    {
        $this->actingAs(User::factory()->withRole('employee')->create());
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')->assertForbidden();
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/reschedule-suggestion')->assertForbidden();
    }

    public function test_absence_blocks_start_and_short_remaining_slot_needs_rescheduling(): void
    {
        DB::table('attendances')->update(['status' => 'absent', 'time_in' => null]);
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.ready', false)->assertJsonPath('data.presence.0.state', 'blocked');
        $this->wo->schedules()->firstOrFail()->update(['scheduled_end' => now()->addMinutes(5)]);
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.needs_reschedule', true);
    }

    public function test_approval_revalidates_versions_and_keeps_production_confirmed(): void
    {
        $result = $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/reschedule-suggestion')
            ->assertOk()->json('data');
        $payload = array_intersect_key($result, array_flip(['scheduled_start', 'scheduled_end', 'expected_schedule_id',
            'expected_schedule_updated_at', 'expected_crew_id', 'recorder_employee_id']));
        $payload += ['employee_ids' => array_column($result['members'], 'id'), 'reason' => 'Approved suggested replacement slot and crew.'];
        $this->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/review-slot', $payload)
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertNull($this->wo->fresh()->actual_start);
        $this->assertNull($this->wo->machine->fresh()->current_work_order_id);
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->travel(30)->seconds();
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.ready', true)->assertJsonPath('data.needs_reschedule', false);
        $payload['reason'] = 'A second stale approval must not overwrite the accepted schedule.';
        $this->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/review-slot', $payload)->assertUnprocessable();
        $this->assertSame(1, $this->wo->schedules()->where('reserves_capacity', true)->count());
    }

    public function test_no_available_crew_or_equipment_deadline_returns_blockers_without_mutation(): void
    {
        $this->wo->update(['planned_end' => now()->addMinutes(10)]);
        $before = $this->snapshot();
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/reschedule-suggestion')
            ->assertOk()->assertJsonPath('data.scheduled_start', null)->assertJsonCount(0, 'data.members')
            ->assertJsonPath('data.blockers.0', fn ($message) => str_contains($message, 'deadline'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_manual_review_allows_exact_deadline_but_blocks_later_finish_without_changes(): void
    {
        $deadline = now()->addHour();
        $this->wo->update(['planned_end' => $deadline]);
        $slot = $this->wo->schedules()->firstOrFail();
        $payload = ['scheduled_start' => now()->addMinutes(20)->toIso8601String(),
            'scheduled_end' => $deadline->copy()->addMinute()->toIso8601String(),
            'expected_schedule_id' => $slot->hash_id, 'expected_schedule_updated_at' => $slot->updated_at->toISOString(),
            'reason' => 'Manual deadline check.'];
        $before = $this->snapshot();
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/review-slot';
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->postJson($url, $payload + ['late_production_authorized' => true,
            'late_production_reason' => 'Confirmed production cannot override the deadline.'])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $payload['scheduled_end'] = $deadline->toIso8601String();
        $this->postJson($url, $payload)->assertOk();
        $this->assertTrue($this->wo->schedules()->where('reserves_capacity', true)->firstOrFail()->scheduled_end->equalTo($deadline));
    }

    public function test_paused_late_suggestion_requires_explicit_approval_and_execution_rechecks_evidence(): void
    {
        $this->wo->update(['planned_end' => now()->addMinutes(10)]);
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id;
        $this->getJson($url.'/reschedule-suggestion')->assertOk()->assertJsonPath('data.scheduled_start', null);
        $this->wo->update(['status' => 'paused']);
        $before = $this->snapshot();
        $suggestion = $this->getJson($url.'/reschedule-suggestion')->assertOk()
            ->assertJsonPath('data.requires_late_production_approval', true)->json('data');
        $this->assertSame($before, $this->snapshot());
        $payload = array_intersect_key($suggestion, array_flip(['scheduled_start', 'scheduled_end',
            'expected_schedule_id', 'expected_schedule_updated_at']));
        $payload['reason'] = 'Remaining work exceeds the deadline.';
        $this->postJson($url.'/review-slot', $payload)->assertUnprocessable();
        $this->postJson($url.'/review-slot', $payload + ['late_production_authorized' => true])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->postJson($url.'/review-slot', $payload + ['late_production_authorized' => true,
            'late_production_reason' => 'QC and delivery planning will review the delay.'])->assertOk();
        $event = DB::table('activity_events')->where('type', 'production.schedule')->where('action', 'reviewed')->latest('id')->first();
        $detail = json_decode($event->detail, true);
        $this->assertTrue($detail['late_production_authorized']);
        $this->assertSame($this->wo->planned_end->toIso8601String(), $detail['production_deadline']);
        app(ProductionResourceService::class)->assertExecutionSlot($this->wo->fresh(), $this->wo->machine, $this->wo->mold);
        $this->wo->update(['planned_end' => now()->addMinutes(5)]);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('New finish exceeds the production deadline');
        app(ProductionResourceService::class)->assertExecutionSlot($this->wo->fresh(), $this->wo->machine, $this->wo->mold);
    }

    public function test_ppc_cannot_authorize_late_production_even_with_a_reason(): void
    {
        $this->wo->update(['planned_end' => now()->addMinutes(10), 'status' => 'paused']);
        $ppc = User::factory()->withRole('ppc_head')->create();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Production Management');
        app(ProductionResourceService::class)->assertDeadlineReview($this->wo,
            now()->addHour(), $ppc, ['late_production_authorized' => true, 'late_production_reason' => 'Not an authorized approver.']);
    }

    public function test_suggestion_replaces_off_shift_worker_and_can_start_only_inside_approved_slot(): void
    {
        $original = $this->wo->crews()->firstOrFail()->recorder_employee_id;
        $late = Shift::create(['name' => 'Afternoon regression', 'start_time' => '14:00', 'end_time' => '22:00', 'is_active' => true]);
        DB::table('employee_shift_assignments')->where('employee_id', $original)->update(['shift_id' => $late->id]);
        $day = Shift::create(['name' => 'Day regression', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        $employee = Employee::factory()->create(['department_id' => Employee::findOrFail($original)->department_id, 'status' => 'active']);
        User::factory()->withRole('employee')->create(['employee_id' => $employee->id, 'is_active' => true]);
        app(ShiftAssignmentService::class)->assignToEmployee($employee->id, $day->id, now()->toDateString(), null, [1, 2, 3, 4, 5, 6]);
        $before = $this->snapshot();
        $result = $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/reschedule-suggestion?preferred_date=2026-10-07')
            ->assertOk()->assertJsonPath('data.members.0.id', $employee->hash_id)->assertJsonCount(0, 'data.blockers')->json('data');
        $this->assertSame($before, $this->snapshot());
        $payload = array_intersect_key($result, array_flip(['scheduled_start', 'scheduled_end', 'expected_schedule_id',
            'expected_schedule_updated_at', 'expected_crew_id', 'recorder_employee_id']));
        $payload += ['employee_ids' => [$employee->hash_id], 'reason' => 'Approve current shift coverage.'];
        $this->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/review-slot', $payload)->assertOk();
        $crew = $this->wo->crews()->whereNull('ended_at')->firstOrFail();
        $execution = ['expected_crew_id' => $crew->hash_id, 'presence_confirmed_employee_ids' => [$employee->hash_id]];
        $this->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/start', $execution)->assertUnprocessable();
        $this->assertNull($this->wo->fresh()->actual_start);
        $this->travelTo(Carbon::parse($result['scheduled_start'])->addMinute());
        $manager = User::findOrFail($this->wo->created_by);
        $manager->update(['last_activity' => now()]);
        $this->actingAs($manager);
        $this->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/start', $execution)
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertNotNull($this->wo->fresh()->actual_start);
        $this->assertSame($this->wo->id, $this->wo->machine->fresh()->current_work_order_id);
    }

    public function test_two_hour_reservation_preserves_estimates_and_scales_for_long_runs(): void
    {
        $resources = app(ProductionResourceService::class);
        $this->assertSame(40, $resources->durationMinutes($this->wo, $this->wo->mold));
        $this->assertSame(120, $resources->reservationMinutes($this->wo, $this->wo->mold));
        $this->wo->update(['quantity_target' => 200]);
        $this->assertSame(145, $resources->reservationMinutes($this->wo, $this->wo->mold));
    }

    private function executionPayload(bool $late = false): array
    {
        $slot = app(WorkOrderAssignmentService::class)->schedule($this->wo);

        return ['expected_crew_id' => $this->wo->crews()->firstOrFail()->hash_id,
            'expected_schedule_id' => $slot->hash_id, 'expected_schedule_updated_at' => $slot->updated_at->toISOString(),
            'late_start_authorized' => $late];
    }

    public function test_early_start_requires_verified_attendance_and_preserves_approved_timing(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $approved = $this->wo->schedules()->firstOrFail();
        $approved->update(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(3)]);
        $originalStart = $approved->scheduled_start->toIso8601String();
        $originalEnd = $approved->scheduled_end->toIso8601String();
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id;
        DB::table('attendances')->update(['time_in' => null]);
        $this->getJson($url.'/execution-readiness')->assertOk()->assertJsonPath('data.ready', false);
        $before = $this->snapshot();
        $this->postJson($url.'/start', $this->executionPayload())->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        DB::table('attendances')->update(['time_in' => now()]);
        $this->getJson($url.'/execution-readiness')->assertOk()->assertJsonPath('data.ready', true)->assertJsonPath('data.start_mode', 'early');
        $this->postJson($url.'/start', $this->executionPayload())->assertOk();
        $this->assertSame($originalStart, $approved->fresh()->scheduled_start->toIso8601String());
        $this->assertSame($originalEnd, $approved->fresh()->scheduled_end->toIso8601String());
        $this->assertFalse($approved->fresh()->reserves_capacity);
        $actual = app(WorkOrderAssignmentService::class)->schedule($this->wo);
        $this->assertTrue($actual->scheduled_start->equalTo(now()));
        $this->assertTrue($actual->scheduled_end->equalTo(now()->addHours(2)));
        $this->assertDatabaseHas('activity_events', ['subject_id' => $this->wo->id, 'action' => 'execution_deviation']);
        $recorder = $this->wo->crews()->firstOrFail()->members()->firstOrFail()->employee->user;
        app(WorkOrderAssignmentService::class)->assertCanRecord($recorder, $this->wo->fresh(), $this->wo->crews()->firstOrFail()->id);
    }

    public function test_early_start_lists_unverified_workers_once_without_allowing_start(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->wo->schedules()->firstOrFail()->update(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(3)]);
        $crew = $this->wo->crews()->firstOrFail();
        $first = $crew->members()->firstOrFail()->employee;
        $second = Employee::factory()->create(['department_id' => $first->department_id, 'status' => 'active']);
        User::factory()->withRole('employee')->create(['employee_id' => $second->id, 'is_active' => true]);
        $assignment = DB::table('employee_shift_assignments')->where('employee_id', $first->id)->first();
        app(ShiftAssignmentService::class)->assignToEmployee($second->id, $assignment->shift_id, now()->toDateString(), null, [1, 2, 3, 4, 5, 6, 7]);
        $crew->members()->create(['employee_id' => $second->id,
            'employee_snapshot' => app(WorkOrderAssignmentService::class)->employeeOption($second)]);
        DB::table('attendances')->delete();
        $before = $this->snapshot();
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id;
        $response = $this->getJson($url.'/execution-readiness')->assertOk()->assertJsonPath('data.ready', false);
        $presence = collect($response->json('data.blockers'))->where('code', 'presence')->values();
        $this->assertCount(1, $presence);
        $this->assertStringContainsString($first->full_name, $presence[0]['message']);
        $this->assertStringContainsString($second->full_name, $presence[0]['message']);
        $this->assertSame($before, $this->snapshot());
        $this->postJson($url.'/start', $this->executionPayload())->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_super_late_start_requires_acknowledgement_and_rechecks_attendance(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->travel(3)->hours();
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id;
        $this->getJson($url.'/execution-readiness')->assertOk()->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.requires_late_start_acknowledgement', true);
        $before = $this->snapshot();
        $this->postJson($url.'/start', $this->executionPayload())->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        DB::table('attendances')->update(['status' => 'absent', 'time_in' => null]);
        $before = $this->snapshot();
        $this->postJson($url.'/start', $this->executionPayload(true))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        DB::table('attendances')->update(['status' => 'present', 'time_in' => now()]);
        $this->postJson($url.'/start', $this->executionPayload(true))->assertOk();
        $this->assertSame('in_progress', $this->wo->fresh()->status->value);
    }

    public function test_fifteen_minute_grace_uses_existing_reservation_and_later_start_requires_acknowledgement(): void
    {
        $this->travel(15)->minutes();
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id;
        $this->getJson($url.'/execution-readiness')->assertOk()->assertJsonPath('data.start_mode', 'scheduled');
        $this->travel(1)->minutes();
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->getJson($url.'/execution-readiness')->assertOk()->assertJsonPath('data.start_mode', 'late');
    }

    public function test_late_acknowledgement_does_not_override_equipment_conflicts_or_deadline(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->travel(3)->hours();
        $other = WorkOrder::factory()->create(['product_id' => $this->wo->product_id, 'status' => 'planned']);
        ProductionSchedule::create(['work_order_id' => $other->id, 'machine_id' => $this->wo->machine_id,
            'mold_id' => $this->wo->mold_id, 'priority_order' => 0, 'scheduled_start' => now()->addMinutes(20), 'scheduled_end' => now()->addHour(),
            'status' => 'pending', 'is_confirmed' => false, 'reserves_capacity' => true]);
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id;
        $before = $this->snapshot();
        $this->getJson($url.'/execution-readiness')->assertOk()->assertJsonPath('data.ready', false);
        $this->postJson($url.'/start', $this->executionPayload(true))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $other->schedules()->update(['reserves_capacity' => false]);
        $this->wo->update(['planned_end' => now()->addMinutes(30)]);
        $before = $this->snapshot();
        $this->postJson($url.'/start', $this->executionPayload(true))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_stale_schedule_and_off_duty_workers_block_late_start_without_mutations(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->travel(3)->hours();
        $payload = $this->executionPayload(true);
        $this->wo->schedules()->firstOrFail()->update(['priority_order' => 1]);
        $before = $this->snapshot();
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/start';
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->travel(5)->hours();
        $this->actingAs(User::factory()->withRole('production_manager')->create(['is_active' => true]));
        $before = $this->snapshot();
        $this->postJson($url, $this->executionPayload(true))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_late_presence_confirmation_uses_actual_reservation_for_recorder_access(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        DB::table('attendances')->delete();
        $this->travel(3)->hours();
        $crew = $this->wo->crews()->firstOrFail();
        $recorder = $crew->members()->firstOrFail()->employee->user;
        $payload = $this->executionPayload(true) + ['presence_confirmed_employee_ids' => [$recorder->employee->hash_id]];
        $this->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/start', $payload)->assertOk();
        $service = app(WorkOrderAssignmentService::class);
        $service->assertCanRecord($recorder, $this->wo->fresh(), $crew->id);
        $this->assertDatabaseHas('activity_events', ['action' => 'presence_confirmed', 'subject_id' => $this->wo->id]);
        $this->assertDatabaseCount('production_schedules', 2);
    }

    public function test_late_resume_keeps_started_schedule_immutable_and_reserves_remaining_time(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->wo->update(['status' => 'paused', 'actual_start' => now()]);
        $this->passInProcessQc($this->wo);
        $approved = $this->wo->schedules()->firstOrFail();
        $start = $approved->scheduled_start->toIso8601String();
        $this->travel(3)->hours();
        $this->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/resume', $this->executionPayload(true))->assertOk();
        $this->assertSame('in_progress', $this->wo->fresh()->status->value);
        $this->assertSame($start, $approved->fresh()->scheduled_start->toIso8601String());
        $this->assertFalse($approved->fresh()->reserves_capacity);
        $this->assertDatabaseCount('production_schedules', 2);
    }

    public function test_late_start_rejects_worker_overlap_even_when_equipment_is_free(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->travel(3)->hours();
        $other = WorkOrder::factory()->create(['status' => 'planned']);
        $machine = Machine::factory()->create();
        $mold = Mold::create(['product_id' => $other->product_id, 'mold_code' => 'OTHER-CREW-MOLD', 'name' => 'Other crew mold',
            'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'setup_time_minutes' => 10,
            'max_shots_before_maintenance' => 10000, 'lifetime_max_shots' => 100000, 'status' => 'available']);
        ProductionSchedule::create(['work_order_id' => $other->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now(), 'scheduled_end' => now()->addHours(2), 'priority_order' => 0,
            'status' => 'pending', 'is_confirmed' => false, 'reserves_capacity' => true]);
        $employeeId = $this->wo->crews()->firstOrFail()->recorder_employee_id;
        $crew = $other->crews()->create(['recorder_employee_id' => $employeeId, 'assigned_by' => auth()->id(),
            'reason' => 'Conflicting worker regression.', 'assigned_at' => now()]);
        $crew->members()->create(['employee_id' => $employeeId, 'employee_snapshot' => []]);
        $before = $this->snapshot();
        $this->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/start', $this->executionPayload(true))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_late_start_retains_approved_end_when_remaining_work_fits_shift(): void
    {
        $slot = $this->wo->schedules()->firstOrFail();
        $end = $slot->scheduled_end->copy();
        $this->travel(16)->minutes();
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id;
        $this->getJson($url.'/execution-readiness')->assertOk()->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.start_mode', 'late')->assertJsonPath('data.execution_end', $end->toIso8601String());
        $this->postJson($url.'/start', $this->executionPayload(true))->assertOk();
        $this->assertTrue(app(WorkOrderAssignmentService::class)->schedule($this->wo)->scheduled_end->equalTo($end));
        $this->assertTrue($slot->fresh()->scheduled_end->equalTo($end));
    }

    public function test_absence_offers_worker_recovery_without_rescheduling(): void
    {
        DB::table('attendances')->update(['status' => 'absent', 'time_in' => null]);
        $result = $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.ready', false)->assertJsonPath('data.needs_reschedule', false)->json('data');
        $this->assertContains('presence', array_column($result['blockers'], 'code'));
        $this->assertNotContains('schedule', array_column($result['blockers'], 'code'));
    }

    public function test_operation_setup_rejects_absent_operator(): void
    {
        $this->wo->update(['status' => 'in_progress', 'actual_start' => now()]);
        $this->passInProcessQc($this->wo);
        DB::table('attendances')->update(['status' => 'absent', 'time_in' => null]);
        $employee = app(WorkOrderAssignmentService::class)->currentCrew($this->wo)->members->first()->employee;
        $op = WoOperation::create(['work_order_id' => $this->wo->id,
            'sequence' => 1, 'operation_name' => 'Injection setup', 'status' => 'pending', 'qty_planned' => 50]);
        $this->expectException(BusinessRuleException::class);
        app(WoOperationService::class)->startSetup($op, $employee);
    }

    public function test_running_extension_preserves_history_and_restores_recorder_access(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->wo->update(['status' => 'in_progress', 'actual_start' => now()]);
        $this->passInProcessQc($this->wo);
        $slot = $this->wo->schedules()->firstOrFail();
        $oldEnd = $slot->scheduled_end->copy();
        $this->travel(2)->hours();
        $this->travel(1)->minutes();
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id;
        $this->assertContains('Run reservation ended. Open the WO and use Extend run to review remaining time.',
            app(CapacityPlanningService::class)->readiness($slot)['execution_blockers']);
        $data = $this->getJson($url.'/execution-readiness')->assertOk()->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.start_mode', 'extend')->json('data');
        $payload = $this->executionPayload() + ['scheduled_end' => $data['execution_end'], 'reason' => 'Approved remaining run time.'];
        $this->postJson($url.'/extend-run', $payload)->assertOk()->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.execution_history.0.detail.reason', 'Approved remaining run time.')
            ->assertJsonPath('data.execution_history.0.detail.approved_end', $oldEnd->toIso8601String());
        $this->assertTrue($slot->fresh()->scheduled_end->equalTo($oldEnd));
        $this->assertFalse($slot->fresh()->reserves_capacity);
        $crews = app(WorkOrderAssignmentService::class);
        $crew = $crews->currentCrew($this->wo);
        $crews->assertCanRecord($crew->members->first()->employee->user, $this->wo->fresh(), $crew->id);
        $this->assertDatabaseHas('activity_events', ['subject_id' => $this->wo->id, 'action' => 'run_extended']);
        $this->assertEmpty(app(CapacityPlanningService::class)->readiness($crews->schedule($this->wo))['execution_blockers']);
        $this->postJson($url.'/extend-run', $payload)->assertUnprocessable();
    }

    public function test_running_extension_rejects_conflict_absence_and_unapproved_deadline(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->wo->update(['status' => 'in_progress', 'actual_start' => now()]);
        $this->passInProcessQc($this->wo);
        $this->travel(2)->hours();
        $this->travel(1)->minutes();
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/extend-run';
        $payload = $this->executionPayload() + ['scheduled_end' => now()->addMinutes(55)->toIso8601String(), 'reason' => 'Run extension safety check.'];
        $this->wo->update(['planned_end' => now()->addMinutes(10)]);
        $before = $this->snapshot();
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->postJson($url, $payload + ['late_production_authorized' => true, 'late_production_reason' => 'Customer delivery impact reviewed.'])->assertOk();
        DB::table('attendances')->update(['status' => 'absent', 'time_in' => null]);
        $before = $this->snapshot();
        $this->postJson($url, $this->executionPayload() + ['scheduled_end' => now()->addHours(2)->toIso8601String(),
            'reason' => 'Absent crew test.', 'late_production_authorized' => true, 'late_production_reason' => 'Delay reviewed.'])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_extension_conflict_and_unprivileged_actor_do_not_mutate_run(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->wo->update(['status' => 'in_progress', 'actual_start' => now()]);
        $this->passInProcessQc($this->wo);
        $this->travel(2)->hours();
        $this->travel(1)->minutes();
        $other = WorkOrder::factory()->create(['product_id' => $this->wo->product_id, 'status' => 'planned']);
        ProductionSchedule::create(['work_order_id' => $other->id, 'machine_id' => $this->wo->machine_id,
            'mold_id' => $this->wo->mold_id, 'priority_order' => 0, 'scheduled_start' => now()->addMinutes(20), 'scheduled_end' => now()->addHour(),
            'status' => 'pending', 'is_confirmed' => false, 'reserves_capacity' => true]);
        $payload = $this->executionPayload() + ['scheduled_end' => now()->addMinutes(55)->toIso8601String(), 'reason' => 'Conflict extension.'];
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/extend-run';
        $before = $this->snapshot();
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->actingAs(User::factory()->withRole('employee')->create());
        $this->postJson($url, $payload)->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_operation_resume_rejects_clocked_out_operator(): void
    {
        $this->wo->update(['status' => 'in_progress', 'actual_start' => now()]);
        $this->passInProcessQc($this->wo);
        DB::table('attendances')->update(['time_out' => now()]);
        $employee = app(WorkOrderAssignmentService::class)->currentCrew($this->wo)->members->first()->employee;
        $op = WoOperation::create(['work_order_id' => $this->wo->id,
            'sequence' => 1, 'operation_name' => 'Injection', 'status' => 'paused', 'qty_planned' => 50]);
        $this->expectException(BusinessRuleException::class);
        app(WoOperationService::class)->resumeOperation($op, $employee);
    }

    public function test_schedule_page_uses_same_late_execution_readiness(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->travel(3)->hours();
        $this->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/execution-readiness')
            ->assertOk()->assertJsonPath('data.ready', true);
        $result = app(CapacityPlanningService::class)->readiness($this->wo->schedules()->firstOrFail());
        $this->assertEmpty($result['execution_blockers']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_operation_output_requires_reason_after_operator_clockout(): void
    {
        $this->wo->update(['status' => 'in_progress', 'actual_start' => now()]);
        $this->passInProcessQc($this->wo);
        DB::table('attendances')->update(['time_out' => now()]);
        $employee = app(WorkOrderAssignmentService::class)->currentCrew($this->wo)->members->first()->employee;
        $op = WoOperation::create(['work_order_id' => $this->wo->id,
            'sequence' => 1, 'operator_id' => $employee->id, 'operation_name' => 'Injection', 'status' => 'in_progress', 'qty_planned' => 50]);
        $this->postJson('/api/v1/production/operations/'.$op->hash_id.'/output', ['qty' => 1])->assertConflict();
        $this->postJson('/api/v1/production/operations/'.$op->hash_id.'/output', ['qty' => 1,
            'recording_reason' => 'Reporting output made before operator clock-out.'])->assertOk();
    }
}
