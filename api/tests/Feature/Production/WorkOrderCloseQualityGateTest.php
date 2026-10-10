<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\NonConformanceReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Support\HasPassedInProcessQc;
use Tests\Support\ReceivesProductionOutput;

/**
 * Close-time quality gate (PR: gaps 1 & 2).
 *
 * A completed work order may only close over settled quality evidence: every
 * in-process/outgoing inspection terminal, and every NCR opened from a failed
 * outgoing inspection of this WO closed or cancelled. The state machine's
 * `completed → closed` edge alone let a WO read as fully resolved while its
 * quality gate had not spoken.
 *
 * Also pins the mold occupancy assert (PR-04) on start()/resume(): pausing
 * frees the machine but leaves the mold InUse, and the scheduler treats InUse
 * molds as available — so between pause and resume the same mold could be
 * promised to a second running WO, which is physically impossible and
 * corrupts the shot-life accounting.
 *
 * WOs here are `non_stock` class (no BOM) so start() needs no material setup.
 */
class WorkOrderCloseQualityGateTest extends TestCase
{
    use RefreshDatabase;
    use ReceivesProductionOutput;
    use HasPassedInProcessQc;

    private WorkOrderService $service;

    private User $user;

    private User $productionManager;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', config('app.timezone')));
        $this->seed(RolePermissionSeeder::class);

        $this->service = app(WorkOrderService::class);
        $this->user = User::factory()->create();
        $this->productionManager = User::factory()->withRole('production_manager')->create();
        $this->productionManager = User::factory()->withRole('production_manager')->create();
        $this->product = Product::create([
            'part_number' => 'CLOSE-T-'.substr(uniqid(), -4),
            'name' => 'Close gate test part',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '5.00',
            'is_active' => true,
        ]);
        $this->spec();
    }

    private function spec(): void
    {
        $spec = InspectionSpec::create([
            'product_id' => $this->product->id,
            'is_active' => true,
            'version' => 1,
            'created_by' => $this->user->id,
        ]);
        InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Outer diameter',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'nominal_value' => '10.0000',
            'tolerance_min' => '9.9000',
            'tolerance_max' => '10.1000',
            'is_critical' => true,
        ]);
        $spec->ensureCurrentRevision();
    }

    private function mold(): Mold
    {
        return Mold::create([
            'mold_code' => 'MD-'.substr(uniqid(), -5),
            'name' => 'Close gate mold',
            'product_id' => $this->product->id,
            'cavity_count' => 2,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 240,
            'setup_time_minutes' => 15,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
    }

    private function machine(): Machine
    {
        return Machine::factory()->create(['status' => 'idle']);
    }

    private function ncr(Inspection $inspection, string $status): NonConformanceReport
    {
        return NonConformanceReport::query()->create([
            'ncr_number' => 'NCR-T-'.substr(uniqid(), -5),
            'source' => NcrSource::InspectionFail->value,
            'severity' => 'high',
            'product_id' => $this->product->id,
            'inspection_id' => $inspection->id,
            'defect_description' => 'Dimension out of tolerance.',
            'affected_quantity' => 100,
            'is_auto_generated' => true,
            'status' => $status,
            'created_by' => $this->user->id,
        ]);
    }

    /** A second planned WO for the same mold on a different machine. */
    private function secondWo(Mold $mold, Machine $machineB, bool $startsLater = false): WorkOrder
    {
        $mold->compatibleMachines()->syncWithoutDetaching([$machineB->id]);

        $workOrder = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'quantity_target' => 50,
            'status' => WorkOrderStatus::Planned->value,
            'work_order_class' => 'non_stock',
            'exception_reason' => 'Second press, same mold',
            'exception_authorized_by' => $this->user->id,
            'planned_start' => ($startsLater ? now()->addHours(2) : now())->toDateTimeString(),
            'planned_end' => now()->addDay(),
            'created_by' => $this->user->id,
        ]);
        $this->assignProductionCrew($workOrder, User::factory()->create());

        return $workOrder;
    }

    /** A started (in_progress) non-stock WO with machine + mold bound. */
    private function startedWo(Mold $mold, Machine $machine): WorkOrder
    {
        $wo = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'quantity_target' => 100,
            'status' => WorkOrderStatus::Planned->value,
            'work_order_class' => 'non_stock',
            'exception_reason' => 'Close-gate test run',
            'exception_authorized_by' => $this->user->id,
            'planned_start' => now()->toDateTimeString(),
            'planned_end' => now()->addDay(),
            'created_by' => $this->user->id,
        ]);
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);

        $this->assignProductionCrew($wo, $this->user);
        $confirmed = $this->service->confirm($wo, $machine->id, $mold->id);

        return $this->service->start($confirmed, $this->productionManager->id);
    }

    private function inspection(WorkOrder $wo, string $stage, string $status, bool $criticalFail = false): Inspection
    {
        if ($stage === InspectionStage::InProcess->value && $status === InspectionStatus::Passed->value) {
            $inspection = $this->passInProcessQc($wo);
        } else {
            $inspection = $stage === InspectionStage::InProcess->value
                ? Inspection::query()
                    ->where('stage', $stage)
                    ->where('entity_type', InspectionEntityType::WorkOrder->value)
                    ->where('entity_id', $wo->id)
                    ->whereNull('wo_operation_id')
                    ->where('status', '!=', InspectionStatus::Cancelled->value)
                    ->first()
                : null;

            if ($inspection) {
                $inspection->forceFill(['status' => $status])->save();
            } else {
                $inspection = Inspection::query()->create([
                    'inspection_number' => 'QC-T-'.substr(uniqid(), -5),
                    'stage' => $stage,
                    'status' => $status,
                    'inspection_mode' => 'lot_checklist',
                    'product_id' => $this->product->id,
                    'entity_type' => InspectionEntityType::WorkOrder->value,
                    'entity_id' => $wo->id,
                    'batch_quantity' => 100,
                    'sample_size' => 5,
                    'accept_count' => 1,
                    'reject_count' => 2,
                    'defect_count' => 0,
                ]);
            }
        }

        $measurement = InspectionMeasurement::query()
            ->where('inspection_id', $inspection->id)
            ->where('sample_index', 1)
            ->first();
        $values = [
            'is_critical' => $criticalFail,
            'is_pass' => $status !== InspectionStatus::Failed->value,
        ];
        if ($measurement) {
            $measurement->update($values);
        } else {
            InspectionMeasurement::create([
                'inspection_id' => $inspection->id,
                'sample_index' => 1,
                'parameter_name' => 'Outer diameter',
                'parameter_type' => 'dimensional',
                ...$values,
            ]);
        }

        return $inspection;
    }

    // ── close() quality gate ────────────────────────────────────────────────

    public function test_close_refused_while_in_process_inspection_is_unresolved(): void
    {
        $wo = $this->startedWo($this->mold(), $this->machine());
        $wo->forceFill(['status' => WorkOrderStatus::Completed->value])->save();
        $this->inspection($wo, InspectionStage::InProcess->value, InspectionStatus::Draft->value);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('have no final result yet');
        $this->service->close($wo->fresh());
    }

    public function test_close_refused_while_outgoing_inspection_awaits_review(): void
    {
        $wo = $this->startedWo($this->mold(), $this->machine());
        $this->passInProcessQc($wo);
        $wo->forceFill(['status' => WorkOrderStatus::Completed->value])->save();
        $this->inspection($wo, InspectionStage::Outgoing->value, InspectionStatus::AwaitingReview->value);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('cannot close');
        $this->service->close($wo->fresh());
    }

    public function test_close_refused_while_ncr_from_failed_outgoing_is_open(): void
    {
        $wo = $this->startedWo($this->mold(), $this->machine());
        $this->passInProcessQc($wo);
        $wo->forceFill(['status' => WorkOrderStatus::Completed->value])->save();
        $inspection = $this->inspection($wo, InspectionStage::Outgoing->value, InspectionStatus::Failed->value, criticalFail: true);
        $this->ncr($inspection, NcrStatus::Open->value);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('are still open');
        $this->service->close($wo->fresh());
    }

    public function test_close_succeeds_once_every_inspection_is_terminal_and_ncrs_settled(): void
    {
        $wo = $this->startedWo($this->mold(), $this->machine());
        $wo->forceFill(['status' => WorkOrderStatus::Completed->value])->save();
        $this->inspection($wo, InspectionStage::InProcess->value, InspectionStatus::Passed->value);
        $failed = $this->inspection($wo, InspectionStage::Outgoing->value, InspectionStatus::Failed->value);
        $this->ncr($failed, NcrStatus::Closed->value);

        $closed = $this->service->close($wo->fresh());

        $this->assertSame(WorkOrderStatus::Closed, $closed->status);
    }

    public function test_close_refuses_a_wo_with_no_inspections_at_all(): void
    {
        $wo = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'status' => WorkOrderStatus::Completed->value,
            'quantity_target' => 100,
            'created_by' => $this->user->id,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('QC coverage');
        $this->service->close($wo->fresh());
    }

    // ── mold occupancy on start()/resume() ─────────────────────────────────

    public function test_start_refused_when_mold_is_bound_to_another_running_wo(): void
    {
        $mold = $this->mold();
        $machineA = $this->machine();
        $machineB = $this->machine();
        $woA = $this->startedWo($mold, $machineA); // mold now InUse, machine A Running

        $woB = $this->secondWo($mold, $machineB, startsLater: true);
        $machineB->update(['status' => 'idle']);
        $this->service->confirm($woB, $machineB->id, $mold->id);

        // The scheduler allows promising an InUse mold; the runtime gate must
        // refuse the start that would physically double-book it.
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Equipment is occupied by WO '.$woA->wo_number.'. Production management must complete or explicitly release that work first.');
        $this->service->start($woB->fresh(), $this->productionManager->id);
    }

    public function test_start_succeeds_on_the_same_mold_after_the_first_wo_completes(): void
    {
        $mold = $this->mold();
        $machine = $this->machine();
        $woA = $this->startedWo($mold, $machine);
        $this->passInProcessQc($woA);
        $this->prepareProductionLedgerSetup();
        $finishedGoods = $this->finishedGoodsItemFor($this->product);
        $finishedGoodsLocation = $this->finishedGoodsLocation();
        $crew = app(WorkOrderAssignmentService::class)->currentCrew($woA);
        $this->assertNotNull($crew);
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/production/my-work-orders/'.$woA->hash_id.'/outputs', [
                'crew_id' => $crew->hash_id,
                'good_count' => 50,
                'reject_count' => 0,
            ], ['X-Idempotency-Key' => 'close-gate-output-'.$woA->hash_id])
            ->assertOk()
            ->assertJsonPath('data.good_count', 50)
            ->assertJsonPath('data.receipt_status', 'generated');

        $output = $woA->outputs()->sole();
        $this->assertSame(50, (int) $output->good_count);
        $this->assertSame(50, (int) $woA->fresh()->quantity_produced);
        $this->assertSame(50, (int) $woA->fresh()->quantity_good);
        $this->assertNotSame('', $output->batch_code);
        $this->assertSame('generated', $output->production_receipt_handoff_status->value);
        $movement = $output->productionReceiptMovement()->firstOrFail();
        $this->assertSame(MovementGlHandoffStatus::Generated, $movement->gl_handoff_status);
        $this->assertNotNull($movement->journal_entry_id);
        $journal = JournalEntry::query()->findOrFail($movement->journal_entry_id);
        $this->assertSame('posted', $journal->status->value);
        $debits = DB::table('journal_entry_lines')->where('journal_entry_id', $journal->id)->sum('debit');
        $credits = DB::table('journal_entry_lines')->where('journal_entry_id', $journal->id)->sum('credit');
        $this->assertSame(0, bccomp((string) $debits, (string) $credits, 2));
        $this->assertSame('50.000', (string) DB::table('stock_levels')
            ->where('item_id', $finishedGoods->id)
            ->where('location_id', $finishedGoodsLocation->id)
            ->value('quantity'));

        // Only a completed WO frees equipment for the next order.
        $completedA = $this->service->complete($woA->fresh(), 'Stop partial run and release equipment for next batch.');
        $this->assertSame(WorkOrderStatus::Completed, $completedA->status);
        $this->assertSame('idle', $machine->fresh()->status->value);
        $this->assertSame('available', $mold->fresh()->status->value);

        $woB = $this->secondWo($mold, $machine);
        $this->service->confirm($woB, $machine->id, $mold->id);
        $started = $this->service->start($woB->fresh(), $this->productionManager->id);

        $this->assertSame(WorkOrderStatus::InProgress, $started->status);
    }

    public function test_resume_refused_when_mold_was_promised_to_another_running_wo(): void
    {
        $mold = $this->mold();
        $machineA = $this->machine();
        $machineB = $this->machine();
        $woA = $this->startedWo($mold, $machineA);
        $this->passInProcessQc($woA);

        // Pause A, then explicitly release its resources before B takes the mold.
        $pausedA = $this->service->pause($woA->fresh(), 'Material shortage', MachineDowntimeCategory::MaterialShortage);
        $this->service->releaseResources($pausedA, 'Release equipment for the second press regression.');

        // B takes the same mold. Resuming A must then fail against B's exact owner.
        $woB = $this->secondWo($mold, $machineB);
        $machineB->update(['status' => 'idle']);
        $this->service->confirm($woB, $machineB->id, $mold->id);
        $this->service->start($woB->fresh(), $this->productionManager->id); // B now runs the mold

        // Resuming A must refuse: the mold is physically on machine B.
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Equipment is occupied by WO '.$woB->wo_number.'. Production management must complete or explicitly release that work first.');
        $this->service->resume($woA->fresh(), $this->productionManager->id);
    }
}
