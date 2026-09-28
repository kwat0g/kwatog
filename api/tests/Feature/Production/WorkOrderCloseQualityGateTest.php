<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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

    private WorkOrderService $service;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkOrderService::class);
        $this->user = User::factory()->create();
        $this->product = Product::create([
            'part_number' => 'CLOSE-T-'.substr(uniqid(), -4),
            'name' => 'Close gate test part',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '5.00',
            'is_active' => true,
        ]);
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
    private function secondWo(Mold $mold, Machine $machineB): WorkOrder
    {
        $mold->compatibleMachines()->syncWithoutDetaching([$machineB->id]);

        return WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'quantity_target' => 50,
            'status' => WorkOrderStatus::Planned->value,
            'work_order_class' => 'non_stock',
            'exception_reason' => 'Second press, same mold',
            'exception_authorized_by' => $this->user->id,
            'planned_start' => now()->subDay(),
            'planned_end' => now()->addDay(),
            'created_by' => $this->user->id,
        ]);
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
            'planned_start' => now()->subDay(),
            'planned_end' => now()->addDay(),
            'created_by' => $this->user->id,
        ]);
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);

        $confirmed = $this->service->confirm($wo, $machine->id, $mold->id);

        return $this->service->start($confirmed, $this->user->id);
    }

    private function inspection(WorkOrder $wo, string $stage, string $status, bool $criticalFail = false): Inspection
    {
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

        InspectionMeasurement::create([
            'inspection_id' => $inspection->id,
            'sample_index' => 1,
            'parameter_name' => 'Outer diameter',
            'parameter_type' => 'dimensional',
            'is_critical' => $criticalFail,
            'is_pass' => $status === 'failed' ? false : true,
        ]);

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
        $wo->forceFill(['status' => WorkOrderStatus::Completed->value])->save();
        $this->inspection($wo, InspectionStage::Outgoing->value, InspectionStatus::AwaitingReview->value);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('cannot close');
        $this->service->close($wo->fresh());
    }

    public function test_close_refused_while_ncr_from_failed_outgoing_is_open(): void
    {
        $wo = $this->startedWo($this->mold(), $this->machine());
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

    public function test_close_succeeds_for_a_wo_with_no_inspections_at_all(): void
    {
        $wo = $this->startedWo($this->mold(), $this->machine());
        $wo->forceFill(['status' => WorkOrderStatus::Completed->value])->save();

        $closed = $this->service->close($wo->fresh());

        $this->assertSame(WorkOrderStatus::Closed, $closed->status);
    }

    // ── mold occupancy on start()/resume() ─────────────────────────────────

    public function test_start_refused_when_mold_is_bound_to_another_running_wo(): void
    {
        $mold = $this->mold();
        $machineA = $this->machine();
        $machineB = $this->machine();
        $this->startedWo($mold, $machineA); // mold now InUse, machine A Running

        $woB = $this->secondWo($mold, $machineB);
        $machineB->update(['status' => 'idle']);
        $this->service->confirm($woB, $machineB->id, $mold->id);

        // The scheduler allows promising an InUse mold; the runtime gate must
        // refuse the start that would physically double-book it.
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('currently bound to work order');
        $this->service->start($woB->fresh(), $this->user->id);
    }

    public function test_start_succeeds_on_the_same_mold_after_the_first_wo_completes(): void
    {
        $mold = $this->mold();
        $machine = $this->machine();
        $woA = $this->startedWo($mold, $machine);
        // complete() requires good output; record it directly (output recording
        // itself is covered by its own suite).
        WorkOrderOutput::create([
            'work_order_id' => $woA->id,
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
            'good_count' => 50,
            'reject_count' => 0,
        ]);
        $woA->forceFill(['quantity_produced' => 50, 'quantity_good' => 50])->save();
        // complete() releases the mold (InUse → Available) and frees the machine.
        $this->service->complete($woA->fresh());

        $woB = $this->secondWo($mold, $machine);
        $machine->update(['status' => 'idle']);
        $this->service->confirm($woB, $machine->id, $mold->id);
        $started = $this->service->start($woB->fresh(), $this->user->id);

        $this->assertSame(WorkOrderStatus::InProgress, $started->status);
    }

    public function test_resume_refused_when_mold_was_promised_to_another_running_wo(): void
    {
        $mold = $this->mold();
        $machineA = $this->machine();
        $machineB = $this->machine();
        $woA = $this->startedWo($mold, $machineA);

        // Pause A: machine A freed, mold stays InUse (deliberate pause() semantics).
        $this->service->pause($woA->fresh(), 'Material shortage', MachineDowntimeCategory::MaterialShortage);

        // B confirms and starts on machine B with the SAME mold — allowed by
        // the scheduler (InUse molds are "available") and, before the fix, by
        // start() too, because the mold had no occupancy gate.
        $woB = $this->secondWo($mold, $machineB);
        $machineB->update(['status' => 'idle']);
        $this->service->confirm($woB, $machineB->id, $mold->id);
        $this->service->start($woB->fresh(), $this->user->id); // B now runs the mold

        // Resuming A must refuse: the mold is physically on machine B.
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('currently bound to work order');
        $this->service->resume($woA->fresh());
    }
}
