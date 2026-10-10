<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Support\HasAssignedProductionCrew;

/**
 * OGAMI-015 — machine double-booking guard in WorkOrderService::confirm().
 *
 * A machine already committed to another Confirmed/InProgress work order
 * cannot take a second one. When both WOs carry schedule rows, the conflict is
 * narrowed to overlapping time windows; otherwise any other active WO on the
 * same machine blocks the confirm.
 *
 * These WOs have NO BOM, so confirm() reserves no materials (empty loop) and we
 * avoid stock-level setup — keeping the test focused on the conflict gate.
 */
class WorkOrderMachineConflictTest extends TestCase
{
    use RefreshDatabase;
    use HasAssignedProductionCrew;

    private WorkOrderService $service;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Silence chain/broadcast events fired after a status change.
        Event::fake();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(WorkOrderService::class);
        $this->user = User::factory()->withRole('production_manager')->create();
        $this->product = Product::create([
            'part_number' => 'WO-CONF-1',
            'name' => 'Conflict Product',
            'unit_of_measure' => 'pcs',
            'standard_cost' => 10.00,
            'is_active' => true,
        ]);
        app(InspectionSpecService::class)->upsertForProduct($this->product->id, [
            ['parameter_name' => 'Surface condition', 'parameter_type' => 'visual'],
        ], $this->user->id);
    }

    private function machine(): Machine
    {
        return Machine::factory()->create(['status' => 'idle']);
    }

    private function mold(): Mold
    {
        return Mold::create([
            'mold_code' => 'MD-'.substr(uniqid(), -5),
            'name' => 'Test Mold',
            'product_id' => $this->product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
    }

    private function plannedWo(?Machine $machine = null, ?Mold $mold = null, ?Carbon $start = null, ?Carbon $end = null): WorkOrder
    {
        if ($machine && $mold) {
            $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);
        }

        $workOrder = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'machine_id' => $machine?->id,
            'mold_id' => $mold?->id,
            'status' => WorkOrderStatus::Planned->value,
            'quantity_target' => 10,
            'planned_start' => ($start ?? Carbon::now()->addHour())->toDateTimeString(),
            'planned_end' => ($end ?? Carbon::now()->addDays(2))->toDateTimeString(),
            'created_by' => $this->user->id,
        ]);
        $this->assignProductionCrew($workOrder, User::factory()->create());

        return $workOrder;
    }

    private function schedule(WorkOrder $wo, Machine $machine, Mold $mold, Carbon $start, Carbon $end): ProductionSchedule
    {
        return ProductionSchedule::create([
            'work_order_id' => $wo->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'priority_order' => 1,
            'status' => 'pending',
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────

    /** Baseline: confirming onto a free machine succeeds. */
    public function test_confirm_succeeds_on_free_machine(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();
        $wo = $this->plannedWo($machine, $mold);

        $confirmed = $this->service->confirm($wo);

        $this->assertSame(WorkOrderStatus::Confirmed, $confirmed->status);
    }

    public function test_standard_no_bom_work_order_cannot_start(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();
        $wo = $this->plannedWo($machine, $mold);
        $confirmed = $this->service->confirm($wo);

        $this->expectException(BusinessRuleException::class);
        $this->service->start($confirmed, $this->user->id);
    }

    public function test_parent_work_order_cannot_start_before_subassembly_child_is_ready(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();
        $parent = $this->plannedWo($machine, $mold);
        $parent->forceFill([
            'work_order_class' => 'non_stock',
            'exception_reason' => 'Assembly dependency test',
            'exception_authorized_by' => $this->user->id,
        ])->save();
        $child = $this->plannedWo();
        $child->forceFill([
            'parent_wo_id' => $parent->id,
            'quantity_target' => 10,
        ])->save();
        $parent->forceFill(['status' => WorkOrderStatus::Confirmed->value])->save();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('waiting for subassembly work orders');

        $this->service->start($parent->fresh(), $this->user->id);
    }

    public function test_parent_work_order_can_start_after_child_produces_required_good_quantity(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();
        $parent = $this->plannedWo($machine, $mold);
        $parent->forceFill([
            'work_order_class' => 'non_stock',
            'exception_reason' => 'Assembly dependency test',
            'exception_authorized_by' => $this->user->id,
        ])->save();
        $child = $this->plannedWo();
        $child->forceFill([
            'parent_wo_id' => $parent->id,
            'quantity_target' => 10,
            'quantity_produced' => 10,
            'quantity_good' => 10,
            'status' => WorkOrderStatus::Completed->value,
        ])->save();

        $confirmed = $this->service->confirm($parent->fresh());
        $this->travelTo($confirmed->planned_start);
        $started = $this->service->start($confirmed, $this->user->id);

        $this->assertSame(WorkOrderStatus::InProgress, $started->status);
    }

    /** An active legacy WO without a slot must block a second machine reservation. */
    public function test_confirm_blocked_when_machine_has_active_wo_without_schedules(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $existing = $this->plannedWo($machine, $mold);
        $existing->forceFill(['status' => WorkOrderStatus::Confirmed->value])->save();
        $second = $this->plannedWo($machine, $this->mold());
        $before = [
            'existing_status' => $existing->fresh()->status,
            'second_status' => $second->fresh()->status,
            'schedules' => ProductionSchedule::query()->count(),
        ];
        $thrown = null;

        try {
            $this->service->confirm($second);
        } catch (BusinessRuleException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(BusinessRuleException::class, $thrown, 'Active unscheduled equipment ownership must block confirmation.');
        $this->assertStringContainsString('without an approved production slot', $thrown->getMessage());
        $this->assertSame($before, [
            'existing_status' => $existing->fresh()->status,
            'second_status' => $second->fresh()->status,
            'schedules' => ProductionSchedule::query()->count(),
        ]);
        $this->assertSame(WorkOrderStatus::Planned, $second->fresh()->status);
    }

    public function test_confirm_api_rejects_active_unscheduled_equipment_ownership_without_writes(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $existing = $this->plannedWo($machine, $mold);
        $existing->forceFill(['status' => WorkOrderStatus::Confirmed->value])->save();
        $second = $this->plannedWo($machine, $mold);
        $crew = app(WorkOrderAssignmentService::class)->currentCrew($second);
        $schedule = ProductionSchedule::create([
            'work_order_id' => $second->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => $second->planned_start,
            'scheduled_end' => $second->planned_end,
            'priority_order' => 1,
            'status' => 'pending',
            'reserves_capacity' => true,
        ]);
        $before = [
            'existing' => $existing->fresh()->getRawOriginal(),
            'second' => $second->fresh()->getRawOriginal(),
            'schedule' => $schedule->fresh()->getRawOriginal(),
            'machine' => $machine->fresh()->getRawOriginal(),
            'mold' => $mold->fresh()->getRawOriginal(),
            'reservations' => DB::table('material_reservations')->count(),
            'stock_movements' => DB::table('stock_movements')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'event_outbox' => DB::table('event_outbox')->count(),
            'chain_step_runs' => DB::table('chain_step_runs')->count(),
        ];

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/production/work-orders/{$second->hash_id}/confirm", [
                'schedule_id' => $schedule->hash_id,
                'schedule_updated_at' => $schedule->updated_at->toISOString(),
                'expected_crew_id' => $crew->hash_id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($message): bool => is_string($message) && str_contains($message, 'without an approved production slot'));

        $after = [
            'existing' => $existing->fresh()->getRawOriginal(),
            'second' => $second->fresh()->getRawOriginal(),
            'schedule' => $schedule->fresh()->getRawOriginal(),
            'machine' => $machine->fresh()->getRawOriginal(),
            'mold' => $mold->fresh()->getRawOriginal(),
            'reservations' => DB::table('material_reservations')->count(),
            'stock_movements' => DB::table('stock_movements')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'event_outbox' => DB::table('event_outbox')->count(),
            'chain_step_runs' => DB::table('chain_step_runs')->count(),
        ];

        $this->assertSame($before, $after);
        $this->assertSame(WorkOrderStatus::Planned, $second->fresh()->status);
    }

    /**
     * Overlapping schedule windows on one machine are rejected at the database.
     *
     * M050 (migration 0479) added the production_schedules_no_overlap
     * exclusion constraint: two active (pending/confirmed/executed) rows with
     * overlapping windows on one machine can no longer be written. The
     * overlapping setup this test originally built to drive the application
     * guard in confirm() is therefore itself refused now — the database is the
     * hard enforcement and the confirm() window check is defense-in-depth.
     */
    public function test_overlapping_schedule_windows_are_rejected_at_the_database(): void
    {
        $machine = $this->machine();

        $firstMold = $this->mold();
        $firstStart = Carbon::now()->addDay()->setTime(8, 0);
        $firstEnd = $firstStart->copy()->addHours(4);
        $existing = $this->plannedWo($machine, $firstMold, $firstStart, $firstEnd);
        $this->schedule(
            $existing, $machine, $firstMold, $firstStart, $firstEnd,
        );
        $this->service->confirm($existing);

        $secondMold = $this->mold();
        $secondStart = $firstStart->copy()->addHours(2);
        $secondEnd = $secondStart->copy()->addHours(4);
        $second = $this->plannedWo($machine, $secondMold, $secondStart, $secondEnd);
        $this->expectException(QueryException::class);
        // Overlaps 10:00–14:00 with the confirmed 08:00–12:00 window.
        $this->schedule($second, $machine, $secondMold, $secondStart, $secondEnd);
    }

    public function test_overlapping_schedule_windows_are_rejected_for_a_shared_mold_across_machines(): void
    {
        $machineA = $this->machine();
        $machineB = $this->machine();
        $mold = $this->mold();
        $firstStart = Carbon::now()->addDay()->setTime(8, 0);
        $secondStart = $firstStart->copy()->addHours(2);
        $first = $this->plannedWo($machineA, $mold, $firstStart, $firstStart->copy()->addHours(4));
        $second = $this->plannedWo($machineB, $mold, $secondStart, $secondStart->copy()->addHours(4));
        $this->schedule(
            $first,
            $machineA,
            $mold,
            $firstStart,
            $firstStart->copy()->addHours(4),
        );

        $this->expectException(QueryException::class);
        $this->schedule(
            $second,
            $machineB,
            $mold,
            $secondStart,
            $secondStart->copy()->addHours(4),
        );
    }

    /**
     * Non-overlapping schedule windows on the same machine → allowed.
     */
    public function test_confirm_allowed_on_non_overlapping_schedule_window(): void
    {
        $machine = $this->machine();

        $firstMold = $this->mold();
        $firstStart = Carbon::now()->addDay()->setTime(8, 0);
        $firstEnd = $firstStart->copy()->addHours(4);
        $existing = $this->plannedWo($machine, $firstMold, $firstStart, $firstEnd);
        $this->schedule(
            $existing, $machine, $firstMold, $firstStart, $firstEnd,
        );
        $this->service->confirm($existing);

        $secondMold = $this->mold();
        $secondStart = $firstEnd->copy();
        $secondEnd = $secondStart->copy()->addHours(4);
        $second = $this->plannedWo($machine, $secondMold, $secondStart, $secondEnd);
        // Starts exactly when the first ends — no overlap (half-open interval).
        $this->schedule(
            $second, $machine, $secondMold,
            $secondStart, $secondEnd,
        );

        $confirmed = $this->service->confirm($second);

        $this->assertSame(WorkOrderStatus::Confirmed, $confirmed->status);
    }
}
