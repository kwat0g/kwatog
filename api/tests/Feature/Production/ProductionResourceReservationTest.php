<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\CapacityPlanningService;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\ProductionResourceService;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionResourceReservationTest extends TestCase
{
    use RefreshDatabase;

    private function resources(): array
    {
        $wo = WorkOrder::factory()->create(['planned_end' => now()->addDays(3), 'quantity_target' => 50, 'status' => 'confirmed']);
        $machine = Machine::factory()->create(['status' => 'idle', 'available_hours_per_day' => 8]);
        $mold = Mold::create(['product_id' => $wo->product_id, 'mold_code' => 'RES-MOLD', 'name' => 'Resource mold', 'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10, 'current_shot_count' => 0, 'max_shots_before_maintenance' => 1000, 'lifetime_max_shots' => 10000, 'status' => 'available']);
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo->update(['machine_id' => $machine->id, 'mold_id' => $mold->id]);
        $this->slot($wo, $machine, $mold);

        return [$wo, $machine, $mold, app(ProductionResourceService::class)];
    }

    private function slot(WorkOrder $wo, Machine $machine, Mold $mold): ProductionSchedule
    {
        return ProductionSchedule::create(['work_order_id' => $wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now()->subMinute(), 'scheduled_end' => now()->addHours(2), 'priority_order' => 0,
            'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true]);
    }

    public function test_late_start_rejects_insufficient_remaining_time_without_claiming_equipment(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 03:06:00', config('app.timezone')));
        [$wo, $machine, $mold, $service] = $this->resources();
        $wo->update(['quantity_target' => 50]);
        $mold->update(['output_rate_per_hour' => 40, 'setup_time_minutes' => 20]);
        $slot = $wo->schedules()->firstOrFail();
        $slot->update(['scheduled_start' => now(), 'scheduled_end' => now()->addMinutes(95)]);
        $service->assertExecutionSlot($wo, $machine, $mold);
        $this->travel(94)->minutes();
        $readiness = app(CapacityPlanningService::class)->readiness($slot->fresh());
        $this->assertNotContains('Not enough scheduled time: 95 minutes needed, 1 minute left. Click Reschedule to choose a new slot.', $readiness['execution_blockers']);
        $this->assertContains('Assign a crew and one output recorder before starting or recording production.', $readiness['execution_blockers']);
        try {
            $service->acquire($wo, $machine, $mold);
            $this->fail('Late start cannot fit.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('95 minutes needed, 1 minute left', $error->getMessage());
        }
        $this->assertNull($machine->fresh()->current_work_order_id);
        $this->assertNull($mold->fresh()->current_work_order_id);
    }

    public function test_future_confirmation_is_not_physical_ownership_and_pause_release_is_exact(): void
    {
        [$wo,$machine,$mold,$service] = $this->resources();
        $future = WorkOrder::factory()->create(['product_id' => $wo->product_id, 'status' => 'confirmed', 'quantity_target' => 50, 'planned_end' => now()->addDays(3), 'machine_id' => $machine->id, 'mold_id' => $mold->id]);
        $readiness = app(CapacityPlanningService::class)->readiness($wo->schedules()->firstOrFail());
        $futureAssignmentBlocker = "Equipment is assigned to active work order {$future->wo_number} without an approved production slot. Production management must reconcile the existing assignment before confirming this work order.";
        $this->assertNotContains($futureAssignmentBlocker, $readiness['blockers']);
        $this->assertNotContains($futureAssignmentBlocker, $readiness['execution_blockers']);
        $service->acquire($wo, $machine, $mold);
        $this->assertSame($wo->id, (int) $mold->fresh()->current_work_order_id);
        $wo->update(['status' => 'paused']);
        try {
            $service->acquire($future, $machine->fresh(), $mold->fresh());
            $this->fail('Installed mold cannot be taken.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('occupied', $e->getMessage());
        }
        $released = app(WorkOrderService::class)->releaseResources($wo->fresh(), 'Explicitly release equipment for the scheduled future work order.');
        $this->assertNotNull($released->production_released_at);
        $this->assertNull($released->pause_reason, 'Releasing resources must not overwrite the pause cause.');
        $this->assertDatabaseHas('activity_events', [
            'subject_id' => $wo->id,
            'type' => 'production.work_order',
            'action' => 'resources_released',
        ]);
        $this->slot($future, $machine, $mold);
        $service->acquire($future, $machine->fresh(), $mold->fresh());
        $this->assertSame($future->id, (int) $mold->fresh()->current_work_order_id);
        $service->release($wo, $machine->fresh(), $mold->fresh());
        $this->assertSame($future->id, (int) $mold->fresh()->current_work_order_id);
    }

    public function test_start_is_blocked_when_another_in_progress_work_order_has_no_slot(): void
    {
        [$wo, $machine, $mold, $service] = $this->resources();
        $legacy = WorkOrder::factory()->create([
            'product_id' => $wo->product_id,
            'status' => 'in_progress',
            'quantity_target' => 50,
            'planned_end' => now()->addDays(3),
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
        ]);

        try {
            $service->acquire($wo, $machine, $mold);
            $this->fail('An in-progress unscheduled work order must block equipment acquisition.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString($legacy->wo_number, $error->getMessage());
            $this->assertStringContainsString('without an approved production slot', $error->getMessage());
        }

        $this->assertNull($machine->fresh()->current_work_order_id);
        $this->assertNull($mold->fresh()->current_work_order_id);
        $this->assertSame('confirmed', $wo->fresh()->status->value);
        $this->assertSame('in_progress', $legacy->fresh()->status->value);
    }

    public function test_release_preserves_breakdown_and_history_reservation(): void
    {
        [$wo,$machine,$mold,$service] = $this->resources();
        $service->acquire($wo, $machine, $mold);
        $wo->update(['status' => 'paused']);
        $machine->update(['status' => 'breakdown']);
        $slot = $wo->schedules()->latest('id')->firstOrFail();
        $service->release($wo, $machine->fresh(), $mold->fresh());
        $this->assertSame('breakdown', $machine->fresh()->status->value);
        $this->assertFalse($slot->fresh()->reserves_capacity);
        $this->assertSame('confirmed', $slot->fresh()->status->value);
    }

    public function test_future_reservation_requires_known_release_of_current_equipment_owner(): void
    {
        [$wo, $machine, $mold, $service] = $this->resources();
        $service->acquire($wo, $machine, $mold);
        $wo->update(['status' => 'in_progress']);
        $future = WorkOrder::factory()->create(['product_id' => $wo->product_id, 'quantity_target' => 50,
            'status' => 'planned', 'planned_end' => now()->addDays(3)]);
        $service->assertReservation($future, $machine->fresh(), $mold->fresh(), now()->addHours(3), now()->addHours(4));
        $this->travel(3)->hours();
        foreach ([false, true] as $missingSlot) {
            if ($missingSlot) {
                $service->releaseReservation($wo);
            }
            try {
                $service->assertReservation($future, $machine->fresh(), $mold->fresh(), now(), now()->addHour());
                $this->fail('Unknown physical release must not produce a replacement reservation.');
            } catch (BusinessRuleException $error) {
                $this->assertStringContainsString('actual release', $error->getMessage());
            }
            $this->assertSame($wo->id, (int) $mold->fresh()->current_work_order_id);
        }
    }

    public function test_cumulative_reservations_and_lifetime_limits_are_enforced(): void
    {
        [$wo,$machine,$mold,$service] = $this->resources();
        $mold->update(['max_shots_before_maintenance' => 100]);
        $other = WorkOrder::factory()->create(['product_id' => $wo->product_id, 'quantity_target' => 75, 'status' => 'confirmed']);
        ProductionSchedule::create(['work_order_id' => $other->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id, 'priority_order' => 0,
            'scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addHour(), 'status' => 'confirmed']);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('existing reservations');
        $service->assertMoldLife($mold->fresh(), 50, $wo->id);
    }

    public function test_duration_rounds_up_and_slow_mold_increases_slot(): void
    {
        [$wo,$machine,$mold,$service] = $this->resources();
        $this->assertSame(40, $service->durationMinutes($wo, $mold));
        $mold->output_rate_per_hour = 99;
        $this->assertSame(41, $service->durationMinutes($wo, $mold));
        $mold->output_rate_per_hour = 50;
        $this->assertSame(70, $service->durationMinutes($wo, $mold));
    }

    public function test_missing_and_expired_slots_require_review_without_claiming_equipment(): void
    {
        [$wo, $machine, $mold, $service] = $this->resources();
        $wo->schedules()->update(['reserves_capacity' => false]);
        try {
            $service->acquire($wo, $machine, $mold);
            $this->fail('Missing slot must block start.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Review production slot', $error->getMessage());
        }
        $this->assertNull($machine->fresh()->current_work_order_id);
        $this->slot($wo, $machine, $mold);
        $this->travel(3)->hours();
        try {
            $service->acquire($wo, $machine->fresh(), $mold->fresh());
            $this->fail('Expired slot must block start.');
        } catch (BusinessRuleException $error) {
            $this->assertSame('Production slot expired. Review production slot before starting.', $error->getMessage());
        }
        $this->assertNull($mold->fresh()->current_work_order_id);
    }

    public function test_reviewed_paused_slot_preserves_old_timing_and_reacquires_after_release(): void
    {
        [$wo, $machine, $mold, $service] = $this->resources();
        $old = $wo->schedules()->firstOrFail();
        $service->acquire($wo, $machine, $mold);
        $wo->update(['status' => 'paused']);
        $service->release($wo, $machine->fresh(), $mold->fresh());
        $this->travel(3)->hours();
        $slot = DB::transaction(fn () => $service->reviewSlot($wo, now(), now()->addHour(), $wo->created_by, 'Reviewed remaining work.', null, $machine->id, $mold->id));
        $this->assertFalse($old->fresh()->reserves_capacity);
        $this->assertSame($old->scheduled_start->toIso8601String(), $old->fresh()->scheduled_start->toIso8601String());
        $service->acquire($wo, $machine->fresh(), $mold->fresh());
        $this->assertSame($wo->id, $mold->fresh()->current_work_order_id);
        $this->assertTrue($slot->fresh()->reserves_capacity);
    }

    public function test_same_second_schedule_change_invalidates_review_version(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', config('app.timezone')));
        [$wo, $machine, $mold, $service] = $this->resources();
        $slot = $wo->schedules()->firstOrFail();
        $version = $slot->updated_at->toISOString();
        $slot->update(['priority_order' => 1]);
        $this->assertNotSame($version, $slot->fresh()->updated_at->toISOString());
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Production slot changed');
        $service->reviewedSlotReplay($wo, ['expected_schedule_id' => $slot->id,
            'expected_schedule_updated_at' => $version, 'scheduled_start' => $slot->scheduled_start->toISOString(),
            'scheduled_end' => $slot->scheduled_end->toISOString(), 'reason' => 'Stale same-second review'],
            $wo->created_by, null, $machine->id, $mold->id);
    }

    public function test_review_version_rejects_stale_slot_and_replays_identical_review(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', config('app.timezone')));
        [$wo, $machine, $mold, $service] = $this->resources();
        $old = $wo->schedules()->firstOrFail();
        $data = ['expected_schedule_id' => $old->id, 'expected_schedule_updated_at' => $old->updated_at->toIso8601String(),
            'scheduled_start' => now()->toIso8601String(), 'scheduled_end' => now()->addHour()->toIso8601String(), 'reason' => 'Reviewed replacement.'];
        $this->assertFalse($service->reviewedSlotReplay($wo, $data, $wo->created_by, null, $machine->id, $mold->id));
        DB::transaction(fn () => $service->reviewSlot($wo, now(), now()->addHour(), $wo->created_by, 'Reviewed replacement.', null, $machine->id, $mold->id));
        $this->assertTrue($service->reviewedSlotReplay($wo, $data, $wo->created_by, null, $machine->id, $mold->id));
        $data['scheduled_end'] = now()->addHours(2)->toIso8601String();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Production slot changed');
        $service->reviewedSlotReplay($wo, $data, $wo->created_by, null, $machine->id, $mold->id);
    }
}
