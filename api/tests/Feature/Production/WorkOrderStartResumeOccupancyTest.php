<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Enums\MoldStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Exceptions\IllegalLifecycleTransitionException;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\HasAssignedProductionCrew;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

/**
 * PR-03 — runtime machine-occupancy gate in WorkOrderService::start()/resume().
 *
 * confirm() only checks planned schedule windows (assertMachineAvailable), but
 * a pause frees the machine at runtime. Without a re-check at start/resume the
 * machine can end up bound to two in_progress work orders at once — OEE output
 * double-attributed, one mold feeding two shot counters. These tests pin the
 * runtime fence: the locked machine row must be free or already bound to THIS
 * work order, and the mold must still be assignable (not Maintenance/Retired).
 *
 * The WOs are no-BOM (non_stock class + authorized reason) so start() passes
 * assertMaterialPlan() without stock-level setup.
 */
class WorkOrderStartResumeOccupancyTest extends TestCase
{
    use HasAssignedProductionCrew;
    use HasPassedInProcessQc;
    use RefreshDatabase;

    private WorkOrderService $service;

    private User $user;

    private User $productionManager;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', config('app.timezone')));
        $this->seed(RolePermissionSeeder::class);

        $this->service = app(WorkOrderService::class);
        $this->user = User::factory()->create();
        $this->productionManager = User::factory()->withRole('production_manager')->create();
        $this->product = Product::create([
            'part_number' => 'WO-OCC-1',
            'name' => 'Occupancy Product',
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

    private function nonStockWo(Machine $machine, Mold $mold): WorkOrder
    {
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);

        $workOrder = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'status' => WorkOrderStatus::Planned->value,
            'quantity_target' => 100,
            'planned_start' => now()->toDateTimeString(),
            'planned_end' => Carbon::today()->addDays(2)->toDateTimeString(),
            'work_order_class' => 'non_stock',
            'exception_reason' => 'Occupancy gate test',
            'exception_authorized_by' => $this->user->id,
            'created_by' => $this->user->id,
        ]);
        $actor = User::factory()->create();
        $this->assignProductionCrew($workOrder, $actor);

        return $workOrder;
    }

    private function startedWo(Machine $machine, Mold $mold): WorkOrder
    {
        $workOrder = $this->service->start(
            $this->service->confirm($this->nonStockWo($machine, $mold)),
            $this->productionManager->id,
        );
        $this->passInProcessQc($workOrder);

        return $workOrder;
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * THE repro: WO1 paused (machine freed) → WO2 confirmed + started on the
     * same machine → WO1 resumed. The resume must be refused with an
     * operator-actionable message; WO1 stays paused and the machine stays on
     * WO2.
     */
    public function test_resume_refused_when_another_work_order_took_the_machine_during_pause(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo1 = $this->startedWo($machine, $mold);
        $paused1 = $this->service->pause($wo1, 'Material shortage', MachineDowntimeCategory::MaterialShortage);

        $this->service->releaseResources($paused1, 'Explicit equipment release for revised production slot.');
        $this->travel(10)->minutes();
        $wo2 = $this->startedWo($machine, $mold);

        try {
            $this->service->resume($paused1, $this->productionManager->id);
            $this->fail('resume() must refuse a machine that is running another work order.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(
                'Equipment is occupied by WO '.$wo2->wo_number.'. Production management must complete or explicitly release that work first.',
                $e->getMessage()
            );
        }

        $this->assertSame(WorkOrderStatus::Paused, $paused1->fresh()->status);
        $this->assertSame(WorkOrderStatus::InProgress, $wo2->fresh()->status);

        $freshMachine = $machine->fresh();
        $this->assertSame(MachineStatus::Running, $freshMachine->status);
        $this->assertSame($wo2->id, $freshMachine->current_work_order_id);

        $downtime = MachineDowntime::where('work_order_id', $wo1->id)->firstOrFail();
        $this->assertNull($downtime->end_time, 'The refused resume must roll back its downtime close.');
    }

    public function test_start_refused_when_machine_is_already_running_another_work_order(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo1 = $this->startedWo($machine, $mold);

        $wo2 = $this->nonStockWo($machine, $mold);
        $wo2->forceFill(['status' => WorkOrderStatus::Confirmed->value])->save();

        try {
            $this->service->start($wo2, $this->productionManager->id);
            $this->fail('start() must refuse a machine that is running another work order.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(
                'Equipment is occupied by WO '.$wo1->wo_number.'. Production management must complete or explicitly release that work first.',
                $e->getMessage()
            );
        }

        $this->assertSame(WorkOrderStatus::Confirmed, $wo2->fresh()->status);
        $this->assertSame(WorkOrderStatus::InProgress, $wo1->fresh()->status);
        $this->assertSame($wo1->id, $machine->fresh()->current_work_order_id);
    }

    public function test_resume_refused_when_mold_went_to_maintenance_during_pause(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);
        $paused = $this->service->pause($wo, 'Planned maintenance', MachineDowntimeCategory::PlannedMaintenance);

        $mold->update(['status' => MoldStatus::Maintenance->value]);

        try {
            $this->service->resume($paused, $this->productionManager->id);
            $this->fail('resume() must refuse a mold that entered maintenance during the pause.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Assigned mold is not available to resume production.', $e->getMessage());
        }

        $this->assertSame(WorkOrderStatus::Paused, $paused->fresh()->status);
        $this->assertSame(MachineStatus::Running, $machine->fresh()->status);
        $this->assertSame($wo->id, $machine->fresh()->current_work_order_id);
    }

    public function test_resume_succeeds_when_machine_and_mold_are_still_free(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);
        $paused = $this->service->pause($wo, 'Material shortage', MachineDowntimeCategory::MaterialShortage);

        $resumed = $this->service->resume($paused, $this->productionManager->id);

        $this->assertSame(WorkOrderStatus::InProgress, $resumed->status);
        $this->assertNull($resumed->pause_reason);

        $freshMachine = $machine->fresh();
        $this->assertSame(MachineStatus::Running, $freshMachine->status);
        $this->assertSame($wo->id, $freshMachine->current_work_order_id);

        $downtime = MachineDowntime::where('work_order_id', $wo->id)->firstOrFail();
        $this->assertNotNull($downtime->end_time);
        $this->assertNotNull($downtime->duration_minutes);
    }

    public function test_resume_closes_every_open_downtime_row_for_the_work_order(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);
        $paused = $this->service->pause($wo, 'Material shortage', MachineDowntimeCategory::MaterialShortage);

        MachineDowntime::create([
            'machine_id' => $machine->id,
            'work_order_id' => $wo->id,
            'start_time' => now()->subMinutes(30),
            'category' => MachineDowntimeCategory::MaterialShortage->value,
            'description' => 'Legacy leaked row',
        ]);

        $this->service->resume($paused, $this->productionManager->id);

        $this->assertSame(0, MachineDowntime::where('work_order_id', $wo->id)->whereNull('end_time')->count(),
            'Resume must close every open downtime row for the work order.');
    }

    public function test_cancelling_a_paused_work_order_closes_its_open_downtime(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);
        $paused = $this->service->pause($wo, 'Material shortage', MachineDowntimeCategory::MaterialShortage);

        $cancelled = $this->service->cancel($paused, 'Wrong product was started.');

        $this->assertSame(WorkOrderStatus::Cancelled, $cancelled->status);
        $this->assertSame(0, MachineDowntime::where('work_order_id', $wo->id)->whereNull('end_time')->count(),
            'Cancelling a paused work order must close its open downtime row.');
        $closed = MachineDowntime::where('work_order_id', $wo->id)->firstOrFail();
        $this->assertNotNull($closed->end_time);
        $this->assertNotNull($closed->duration_minutes);
    }

    public function test_start_closes_open_downtime_rows_when_a_paused_work_order_goes_through_start(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);
        $paused = $this->service->pause($wo, 'Material shortage', MachineDowntimeCategory::MaterialShortage);

        MachineDowntime::create([
            'machine_id' => $machine->id,
            'work_order_id' => $wo->id,
            'start_time' => now()->subMinutes(30),
            'category' => MachineDowntimeCategory::MaterialShortage->value,
            'description' => 'Legacy leaked row',
        ]);

        $started = $this->service->start($paused, $this->productionManager->id);

        $this->assertSame(WorkOrderStatus::InProgress, $started->status);
        $this->assertSame(0, MachineDowntime::where('work_order_id', $wo->id)->whereNull('end_time')->count(),
            'Starting a paused work order must close its open downtime rows.');
    }

    public function test_start_on_a_machine_already_bound_to_this_work_order_keeps_existing_refusal(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);

        try {
            $this->service->start($wo->fresh(), $this->productionManager->id);
            $this->fail('Double start must stay refused by the state machine.');
        } catch (IllegalLifecycleTransitionException $e) {
            $this->assertSame(
                'Illegal work-order lifecycle transition: in_progress → in_progress.',
                $e->getMessage()
            );
        }

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
        $this->assertSame($wo->id, $machine->fresh()->current_work_order_id);
    }
}
