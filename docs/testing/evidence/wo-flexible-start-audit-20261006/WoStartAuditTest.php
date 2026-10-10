<?php
namespace Tests\Feature\Production;
require_once '/var/www/tests/Feature/Production/WorkOrderExecutionReadinessTest.php';
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\ProductionResourceService;
use App\Modules\MRP\Services\CapacityPlanningService;
use Illuminate\Support\Facades\DB;
class WoStartAuditTest extends WorkOrderExecutionReadinessTest
{
    private function order(): WorkOrder { return WorkOrder::whereHas('mold', fn ($q) => $q->where('mold_code', 'READY-MOLD'))->firstOrFail(); }
    public function test_audit_schedule_page_disagrees_with_safe_late_start(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $this->travel(3)->hours();
        $wo = $this->order();
        $result = $this->getJson('/api/v1/production/work-orders/'.$wo->hash_id.'/execution-readiness')->assertOk()->assertJsonPath('data.ready', true)->json('data');
        $page = app(CapacityPlanningService::class)->readiness($wo->schedules()->firstOrFail());
        $this->assertNotEmpty($page['execution_blockers']);
        fwrite(STDOUT, "\nAUDIT schedule-page mismatch: ".json_encode($page['execution_blockers'])."\n");
    }
    public function test_audit_known_absence_is_classified_as_reschedule_required(): void
    {
        DB::table('attendances')->update(['status' => 'absent', 'time_in' => null]);
        $wo = $this->order();
        $result = $this->getJson('/api/v1/production/work-orders/'.$wo->hash_id.'/execution-readiness')->assertOk()
            ->assertJsonPath('data.needs_reschedule', true)->json('data');
        $this->assertContains('schedule', array_column($result['blockers'], 'code'));
        fwrite(STDOUT, "\nAUDIT absent-worker routing: ".json_encode($result['blockers'])."\n");
    }
    public function test_audit_approved_late_deadline_is_lost_when_start_deviates(): void
    {
        DB::table('shifts')->update(['end_time' => '16:00:00']);
        $wo = $this->order();
        $wo->update(['planned_end' => now()->addMinutes(10), 'status' => 'paused']);
        $this->passInProcessQc($wo);
        $url='/api/v1/production/work-orders/'.$wo->hash_id;
        $proposed=$this->getJson($url.'/reschedule-suggestion')->assertOk()->json('data');
        $payload=array_intersect_key($proposed,array_flip(['scheduled_start','scheduled_end','expected_schedule_id','expected_schedule_updated_at']));
        $this->postJson($url.'/review-slot',$payload+['reason'=>'Approved late repair review.','late_production_authorized'=>true,'late_production_reason'=>'Approved delivery impact.'])->assertOk();
        app(ProductionResourceService::class)->assertExecutionSlot($wo->fresh(),$wo->machine,$wo->mold);
        $this->travel(16)->minutes();
        $result=$this->getJson($url.'/execution-readiness')->assertOk()->assertJsonPath('data.ready',false)->json('data');
        fwrite(STDOUT,"\nAUDIT approved late deadline after 16 minutes: ".json_encode($result['blockers'])."\n");
    }
    public function test_audit_recorder_access_ends_at_reservation_even_if_production_is_running(): void
    {
        DB::table('shifts')->update(['end_time'=>'16:00:00']);
        $wo=$this->order(); $wo->update(['status'=>'in_progress','actual_start'=>now()]);
        $this->travel(2)->hours(); $this->travel(1)->minutes();
        $crew=app(WorkOrderAssignmentService::class)->currentCrew($wo);
        $recorder=$crew->members->first()->employee->user;
        $this->assertSame('present',app(WorkOrderAssignmentService::class)->presence($recorder->employee,now())['state']);
        try { app(WorkOrderAssignmentService::class)->assertCanRecord($recorder,$wo,$crew->id); $this->fail('Expected schedule gate.'); }
        catch (\App\Common\Exceptions\BusinessRuleException $e) {
            $this->assertStringContainsString('schedule no longer permits recording',$e->getMessage());
            fwrite(STDOUT,"\nAUDIT running overrun recorder: ".$e->getMessage()."\n");
        }
    }
    public function test_audit_grace_near_slot_end_still_needs_late_acknowledgement(): void
    {
        $wo=$this->order();
        $wo->schedules()->firstOrFail()->update(['scheduled_end'=>now()->addMinutes(40)]);
        $this->travel(1)->minutes();
        $result=$this->getJson('/api/v1/production/work-orders/'.$wo->hash_id.'/execution-readiness')->assertOk()->json('data');
        $this->assertTrue($result['requires_late_start_acknowledgement'] || $result['needs_reschedule']);
        fwrite(STDOUT,"\nAUDIT legacy exact-duration at 1 minute late: ".json_encode(array_intersect_key($result,array_flip(['ready','start_mode','requires_late_start_acknowledgement','needs_reschedule','blockers'])))."\n");
    }
    public function test_audit_daily_capacity_for_resume_ignores_already_consumed_reservation(): void
    {
        DB::table('shifts')->update(['end_time'=>'16:00:00']);
        $wo=$this->order();$machine=$wo->machine;$machine->update(['available_hours_per_day'=>2.5]);
        $wo->update(['status'=>'paused','actual_start'=>now()]);
        $this->travel(3)->hours();
        $preview=app(ProductionResourceService::class)->executionWindow($wo->fresh(),$machine->fresh(),$wo->mold);
        $this->assertSame('late',$preview['mode']);
        fwrite(STDOUT,"\nAUDIT daily-capacity resume: 2 hours original allocation + 2 hours new reservation passes 2.5-hour machine daily capacity.\n");
    }
    public function test_audit_calendar_maintenance_due_does_not_block_execution_window(): void
    {
        $wo=$this->order();
        \App\Modules\Maintenance\Models\MaintenanceSchedule::create([
            'maintainable_type'=>'machine','maintainable_id'=>$wo->machine_id,'schedule_type'=>'preventive',
            'description'=>'Overdue safety maintenance audit.','interval_type'=>'days','interval_value'=>30,
            'next_due_at'=>now()->subDay(),'is_active'=>true]);
        $preview=app(ProductionResourceService::class)->executionWindow($wo,$wo->machine,$wo->mold);
        $this->assertSame('scheduled',$preview['mode']);
        fwrite(STDOUT,"\nAUDIT overdue calendar maintenance: execution window remains available.\n");
    }
    public function test_audit_sixteen_minute_delay_extends_unnecessarily_beyond_worker_shift(): void
    {
        $wo=$this->order();
        $this->travel(16)->minutes();
        $this->assertTrue(now()->addMinutes(40)->lte($wo->schedules()->firstOrFail()->scheduled_end));
        $result=$this->getJson('/api/v1/production/work-orders/'.$wo->hash_id.'/execution-readiness')->assertOk()->json('data');
        $this->assertFalse($result['ready']);
        $this->assertTrue($result['needs_reschedule']);
        fwrite(STDOUT,"\nAUDIT 16-minute delay: estimated work fits original slot, but new 2-hour reservation exceeds crew shift. ".json_encode($result['blockers'])."\n");
    }
    public function test_audit_operation_setup_accepts_known_absent_crew_member(): void
    {
        $wo=$this->order();$wo->update(['status'=>'in_progress','actual_start'=>now()]);
        $this->passInProcessQc($wo);
        DB::table('attendances')->update(['status'=>'absent','time_in'=>null]);
        $employee=app(WorkOrderAssignmentService::class)->currentCrew($wo)->members->first()->employee;
        $this->assertSame('blocked',app(WorkOrderAssignmentService::class)->presence($employee,now())['state']);
        $op=\App\Modules\Production\Models\WoOperation::create(['work_order_id'=>$wo->id,'sequence'=>1,
            'operation_name'=>'Audit setup','status'=>'pending','qty_planned'=>50,'qty_completed'=>0,'qty_scrapped'=>0]);
        app(\App\Modules\Production\Services\WoOperationService::class)->startSetup($op,$employee);
        $this->assertSame('setup',$op->fresh()->status->value);
        fwrite(STDOUT,"\nAUDIT operation setup accepts known-absent assigned operator while WO is running.\n");
    }

}
