<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Services\FinishedGoodInventoryService;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\MoldService;
use App\Modules\Production\Enums\LateProductionReportStatus;
use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\ProductionReceiptRequested;
use App\Modules\Production\Events\WorkOrderOutputRecorded;
use App\Modules\Production\Exceptions\ProductionReceiptHandoffException;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\LateProductionReport;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\QualityStockHoldService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 6 — Task 55.
 *
 * Records production output for an in-progress WO. X-Idempotency-Key values
 * are durably persisted per work order; cache is only a performance shortcut.
 *
 * On success:
 *  - Persists work_order_outputs + work_order_defects rows.
 *  - Updates WO totals (quantity_produced/good/rejected) + scrap_rate.
 *  - Increments mold shot count atomically (which may auto-flip mold to
 *    Maintenance via MoldService).
 *  - F-04: records a ProductionReceipt stock movement for good output at a
 *    Finished-Goods zone location, linking product part_number to item code.
 *  - Dispatches WorkOrderOutputRecorded event for live dashboard.
 */
class WorkOrderOutputService
{
    private const IDEMPOTENCY_TTL_SECONDS = 86400;

    private const RECEIPT_MANUAL_MESSAGE = 'Stock receipt pending. Ask Warehouse to check inventory setup, then click Retry.';

    public function __construct(
        private readonly MoldService $molds,
        private readonly StockMovementService $movements,
        private readonly SettingsService $settings,
        private readonly WorkOrderMaterialUsageService $materialUsage,
    ) {}

    public function postLateReport(LateProductionReport $report, User $approver): WorkOrderOutput
    {
        abort_unless($approver->is_active && $approver->hasPermission('production.work_orders.lifecycle')
            && $approver->hasPermission('production.wo.record'), 403);

        return DB::transaction(function () use ($report, $approver): WorkOrderOutput {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($report->work_order_id);
            $report = LateProductionReport::query()->lockForUpdate()->findOrFail($report->id);
            $finishFirst = $wo->usesFinishFirstQc();
            if ($report->work_order_output_id) {
                return WorkOrderOutput::query()->findOrFail($report->work_order_output_id);
            }
            $closedCorrection = $finishFirst && $wo->status === WorkOrderStatus::Closed
                && $report->status === LateProductionReportStatus::Correction && trim((string) $report->review_reason) !== '';
            if ($approver->id === $report->recorded_by
                || ($report->status !== LateProductionReportStatus::Pending && ! $closedCorrection)
                || (! in_array($wo->status, [WorkOrderStatus::InProgress, WorkOrderStatus::Paused, WorkOrderStatus::Completed], true) && ! $closedCorrection)) {
                throw new BusinessRuleException('Independent pending-report approval is required. Closed work orders need accounting correction, not automatic posting.');
            }
            $quantity = $report->good_count + $report->reject_count;
            $productionStart = $report->production_started_at->copy()->timezone(config('app.timezone'));
            $productionFinish = $report->production_finished_at->copy()->timezone(config('app.timezone'));
            if ($quantity < 1 || ($finishFirst
                ? $quantity + (int) $wo->quantity_produced > (int) $wo->quantity_target
                : $report->good_count + (int) $wo->quantity_good > (int) $wo->quantity_target)) {
                throw new BusinessRuleException('Report only additional unrecorded pieces. Reconcile overlap or target excess before approval.');
            }
            if ($wo->outputs()->where(function ($query) use ($productionStart, $productionFinish): void {
                $query->where(fn ($query) => $query->where('production_started_at', '<', $productionFinish)->where('production_finished_at', '>', $productionStart))
                    ->orWhere(fn ($query) => $query->whereNull('production_started_at')->whereBetween('recorded_at', [$productionStart, $productionFinish]));
            })->exists()) {
                throw new BusinessRuleException('Recorded output overlaps this production interval. Reconcile the original batch before approval.');
            }
            $this->materialUsage->assertProductionCoverage($wo, (string) $quantity);
            app(WorkOrderMaterialHandoverService::class)->assertForCompletion($wo);
            if (StockMovement::query()->forWorkOrder($wo->id)->handoverSources()
                ->whereHas('handover', fn ($query) => $query->where('handed_over_at', '>', $productionFinish))->exists()) {
                throw new BusinessRuleException('Warehouse handover is dated after this production. Reconcile the original handover evidence before approval.');
            }
            $released = $finishFirst;
            if (! $finishFirst) {
                try {
                    $wo->assertProcessQualityReleased();
                    $released = true;
                } catch (BusinessRuleException) {
                }
            }
            $process = $wo->inspections()->where('stage', 'in_process')->whereNull('wo_operation_id')->whereNull('work_order_output_id')->where('status', '!=', 'cancelled')->lockForUpdate()->first();
            $releaseTime = $process?->status === InspectionStatus::Failed
                ? $process->ncr()->first()?->sorting_approved_at : $process?->completed_at;
            $released = $finishFirst || ($released && $releaseTime && $releaseTime->lte($report->production_started_at));
            $trial = ! $released && $process?->startup_trial_required && ! $process->trialOutput()->exists()
                && in_array($process->status, [InspectionStatus::Draft, InspectionStatus::InProgress], true)
                && $quantity === min((int) $process->sample_size, (int) $wo->quantity_target) && (int) $wo->quantity_produced === 0;
            $output = WorkOrderOutput::create([
                'work_order_id' => $wo->id, 'recorded_by' => $report->recorded_by, 'recorded_at' => now(),
                'production_started_at' => $productionStart, 'production_finished_at' => $productionFinish,
                'work_order_crew_id' => $report->work_order_crew_id, 'crew_snapshot' => $report->crew_snapshot,
                'recording_reason' => $report->reason, 'remarks' => 'Late report: '.$report->source_reference,
                'batch_code' => $report->source_reference,
                'good_count' => $released ? $report->good_count : 0, 'reject_count' => $released ? $report->reject_count : 0,
                'held_quantity' => $released || $trial ? 0 : $quantity,
                'trial_inspection_id' => $trial ? $process->id : null, 'trial_quantity' => $trial ? $quantity : null,
                'idempotency_key' => 'late-report:'.$report->id, 'idempotency_fingerprint' => $report->idempotency_fingerprint,
                'production_receipt_handoff_status' => $released && $report->good_count > 0 ? 'not_started' : 'not_required',
                'material_lineage' => ['materials' => array_map(static fn (array $usage): array => [
                    'item_id' => $usage['item_id'], 'bom_quantity' => $usage['required_quantity'],
                    'actual_quantity_issued' => $usage['actual_quantity_issued'], 'actual_cost' => $usage['actual_cost'],
                ], $this->materialUsage->groups($wo)), 'material_lot_references' => $wo->material_lot_references ?? []],
            ]);
            $wo->update(['quantity_produced' => (int) $wo->quantity_produced + $quantity,
                'quantity_good' => (int) $wo->quantity_good + $output->good_count,
                'quantity_rejected' => (int) $wo->quantity_rejected + $output->reject_count,
                'scrap_rate' => round(((int) $wo->quantity_rejected + $output->reject_count) / ((int) $wo->quantity_produced + $quantity) * 100, 2)]);
            $historicalMold = $report->evidence_summary['resources']['mold_id'] ?? null;
            $moldId = $historicalMold ? Mold::decodeHash($historicalMold) : $wo->mold_id;
            if ($moldId) {
                $this->molds->incrementShots(Mold::query()->lockForUpdate()->findOrFail($moldId), $quantity);
            }
            if ($released) {
                foreach ($report->defects as $defect) {
                    $output->defects()->create($defect);
                }
                if ($output->good_count > 0) {
                    $this->receiveHistoricalGood($output, $wo, $approver);
                }
            } elseif (! $trial) {
                app(InspectionService::class)->createForHeldOutput($output, $wo, $approver);
            }
            app(OutboxService::class)->record(new WorkOrderOutputRecorded($wo->fresh(), $output->fresh()));

            return $output->fresh();
        });
    }

    private function receiveHistoricalGood(WorkOrderOutput $output, WorkOrder $wo, User $by): void
    {
        try {
            $this->createProductionReceipt($output, $wo, $by->id);
        } catch (ProductionReceiptHandoffException|BusinessRuleException|InvalidMovementException $error) {
            $this->markProductionReceiptManual($output->id);
            app(OutboxService::class)->record(new ProductionReceiptRequested($output->fresh(), 'historical_receipt_requires_recovery'));
        }
    }

    public function settleHistoricalHeld(Inspection $inspection, User $by, int $good, array $defects): void
    {
        $wo = WorkOrder::query()->lockForUpdate()->findOrFail($inspection->entity_id);
        $output = WorkOrderOutput::query()->lockForUpdate()->findOrFail($inspection->work_order_output_id);
        $ncr = $inspection->ncr()->firstOrFail();
        $total = $output->physical_count;
        $reject = $total - $good;
        if (! $by->is_active || ! $by->hasPermission('quality.ncr.manage') || $inspection->status !== InspectionStatus::Failed
            || ! $inspection->isMakerChecked() || $ncr->sorting_approved_by !== $by->id
            || (int) $ncr->sorting_inspected_quantity !== $total || (int) $ncr->mrb_accepted_quantity !== $good
            || $good < 0 || $reject < 0
            || array_sum(array_column($defects, 'count')) !== $reject) {
            throw new BusinessRuleException('Independent full-batch sorting and explicit good/scrap quantities are required.');
        }
        if ($output->held_settled_at) {
            $storedDefects = $output->defects()->pluck('count', 'defect_type_id')->sortKeys()->all();
            $requestedDefects = collect($defects)->mapWithKeys(fn ($defect) => [$defect['defect_type_id'] => $defect['count']])->sortKeys()->all();
            if ($output->good_count !== $good || $output->reject_count !== $reject || $storedDefects !== $requestedDefects) {
                throw new BusinessRuleException('Recovery was already posted with different quantities. Reconcile the original batch.');
            }

            return;
        }
        if ($good + (int) $wo->quantity_good > (int) $wo->quantity_target) {
            throw new BusinessRuleException('Recovered good quantity exceeds the work order target. Reconcile the batch before release.');
        }
        if ($output->held_quantity !== $total) {
            throw new BusinessRuleException('Held source changed. Reconcile original production.');
        }
        foreach ($defects as $defect) {
            if (($defect['count'] ?? 0) < 1 || ! DefectType::query()->whereKey($defect['defect_type_id'] ?? 0)->where('is_active', true)->exists()) {
                throw new BusinessRuleException('Classify every scrapped piece with an active defect type.');
            }
            $output->defects()->create($defect);
        }
        $output->update(['held_quantity' => 0, 'held_settled_at' => now(), 'good_count' => $good, 'reject_count' => $reject]);
        $wo->update(['quantity_good' => (int) $wo->quantity_good + $good, 'quantity_rejected' => (int) $wo->quantity_rejected + $reject,
            'scrap_rate' => $wo->quantity_produced > 0 ? round(((int) $wo->quantity_rejected + $reject) / (int) $wo->quantity_produced * 100, 2) : 0]);
        $untouched = $wo->inspections()->where('startup_trial_required', true)->whereNull('work_order_output_id')->where('status', 'draft')->whereNull('recorded_result')->whereDoesntHave('trialOutput')->first();
        if ($untouched && ! $untouched->measurements()->where(fn ($q) => $q->whereNotNull('measured_value')->orWhereNotNull('item_result')->orWhereNotNull('is_pass'))->exists()) {
            $untouched->update(['status' => 'cancelled', 'notes' => 'Superseded by actual full-batch historical QC '.$inspection->inspection_number]);
        }
        if ($good > 0) {
            $this->receiveHistoricalGood($output->fresh(), $wo->fresh(), $by);
        }
    }

    public function recordTrial(WorkOrder $wo, array $data, int $recordedBy, string $key): WorkOrderOutput
    {
        $actor = User::query()->findOrFail($recordedBy);
        abort_unless($actor->is_active, 403);
        if (! $actor->hasPermission('production.wo.record')) {
            app(WorkOrderAssignmentService::class)->internal($actor);
        }
        if ($key === '' || strlen($key) > 128) {
            throw new BusinessRuleException('A trial idempotency key of 1 to 128 characters is required.');
        }
        if (filter_var($data['trial_quantity'] ?? null, FILTER_VALIDATE_INT) === false
            || (int) $data['trial_quantity'] <= 0) {
            throw new BusinessRuleException('Trial quantity must be a positive whole number.');
        }
        $quantity = (int) $data['trial_quantity'];
        $fingerprint = hash('sha256', json_encode([
            'trial_quantity' => $quantity, 'recorded_by' => $recordedBy, 'crew_id' => $data['crew_id'] ?? null,
            'recording_reason' => $data['recording_reason'] ?? null,
            'material_handover_acknowledged' => $data['material_handover_acknowledged'] ?? null,
            'material_handover_reason' => $data['material_handover_reason'] ?? null,
        ], JSON_THROW_ON_ERROR));

        $output = DB::transaction(function () use ($wo, $data, $actor, $recordedBy, $key, $quantity, $fingerprint): WorkOrderOutput {
            $fresh = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            $existing = $fresh->outputs()->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->trial_inspection_id === null || $existing->idempotency_fingerprint !== $fingerprint
                    || (int) $existing->recorded_by !== $recordedBy) {
                    throw new BusinessRuleException('The idempotency key was already used for a different production output payload.');
                }

                return $existing->load('defects.defectType');
            }
            if ($fresh->status !== WorkOrderStatus::InProgress || ! $fresh->actual_start
                || $fresh->parent_wo_id || $fresh->parent_ncr_id || $fresh->rework_source_output_id) {
                throw new BusinessRuleException('Only an in-progress root production work order can record startup trial pieces.');
            }
            $inspection = Inspection::query()->where('entity_type', InspectionEntityType::WorkOrder->value)
                ->where('entity_id', $fresh->id)->where('stage', InspectionStage::InProcess->value)
                ->whereNull('wo_operation_id')->whereNull('work_order_output_id')
                ->where('status', '!=', InspectionStatus::Cancelled->value)->orderByDesc('id')->lockForUpdate()->first();
            if (! $inspection || ! $inspection->startup_trial_required
                || ! in_array($inspection->status, [InspectionStatus::Draft, InspectionStatus::InProgress], true)) {
                throw new BusinessRuleException('An unfinished startup inspection requiring trial pieces is needed before capture.');
            }
            $required = min((int) $inspection->sample_size, (int) $fresh->quantity_target);
            if ($required < 1 || $quantity !== $required) {
                throw new BusinessRuleException("Record exactly {$required} trial pieces for the pinned startup sample.");
            }
            if ($fresh->outputs()->whereNotNull('trial_inspection_id')->lockForUpdate()->exists()) {
                throw new BusinessRuleException('Startup trial pieces have already been captured for this work order.');
            }
            if (! $fresh->machine_id || ! $fresh->mold_id) {
                throw new BusinessRuleException('Trial production requires both assigned machine and mold.');
            }
            $machine = Machine::query()->lockForUpdate()->findOrFail($fresh->machine_id);
            $mold = Mold::query()->lockForUpdate()->findOrFail($fresh->mold_id);
            app(ProductionResourceService::class)->assertCanAcquire($fresh, $machine, $mold);
            if ((int) $machine->current_work_order_id !== $fresh->id || (int) $mold->current_work_order_id !== $fresh->id) {
                throw new BusinessRuleException('Equipment ownership must be acquired before recording trial pieces.');
            }
            $assignments = app(WorkOrderAssignmentService::class);
            $crew = $assignments->assertCanRecord($actor, $fresh, (int) ($data['crew_id'] ?? 0), recordingReason: $data['recording_reason'] ?? null);
            $assignments->assertCrewPresent($fresh, $crew);
            if ($actor->employee_id !== $crew->recorder_employee_id && trim($data['recording_reason'] ?? '') === '') {
                throw new BusinessRuleException('Explain why you are recording trial pieces on behalf of the assigned crew.');
            }
            $crewSnapshot = $assignments->serialize($crew, $assignments->schedule($fresh)?->scheduled_start);
            $recorderSnapshot = collect($crewSnapshot['members'])->firstWhere('id', app('hashids')->encode($crew->recorder_employee_id));
            $handoverException = app(WorkOrderMaterialHandoverService::class)->assertForOutput($fresh, $actor, $data);
            $this->materialUsage->assertProductionCoverage($fresh, pendingCanonicalUnits: number_format($quantity, 4, '.', ''));
            $output = WorkOrderOutput::create([
                'work_order_id' => $fresh->id, 'recorded_by' => $recordedBy, 'recorded_at' => now(),
                'work_order_crew_id' => $crew->id, 'crew_snapshot' => $crewSnapshot,
                'recording_reason' => $data['recording_reason'] ?? null, 'material_handover_exception' => $handoverException,
                'good_count' => 0, 'reject_count' => 0, 'shift' => $recorderSnapshot['shift']['name'] ?? null,
                'batch_code' => sprintf('%s-B%02d', $fresh->wo_number, $fresh->outputs()->count() + 1),
                'trial_inspection_id' => $inspection->id, 'trial_quantity' => $quantity,
                'idempotency_key' => $key, 'idempotency_fingerprint' => $fingerprint,
                'material_lineage' => [
                    'work_order_class' => $fresh->work_order_class ?: 'standard',
                    'exception_reason' => $fresh->exception_reason, 'authorized_by' => $fresh->exception_authorized_by,
                    'material_plan_source' => $fresh->material_plan_source,
                    'materials' => array_map(static fn (array $group): array => [
                        'item_id' => $group['item_id'], 'bom_quantity' => $group['required_quantity'],
                        'actual_quantity_issued' => $group['actual_quantity_issued'], 'actual_cost' => $group['actual_cost'],
                    ], $this->materialUsage->groups($fresh)),
                    'material_lot_references' => $fresh->material_lot_references ?? [],
                ],
                'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::NotRequired->value,
                'production_receipt_handoff_at' => now(),
            ]);
            $fresh->update(['quantity_produced' => (int) $fresh->quantity_produced + $quantity]);
            $this->molds->incrementShots($mold, $quantity);
            app(OutboxService::class)->record(new WorkOrderOutputRecorded($fresh->fresh(), $output));

            return $output;
        });
        ProductionDashboardService::forgetCache();

        return $output;
    }

    /** Call inside the write transaction, before taking the inspection lock. */
    public function lockTrialSource(Inspection $inspection): void
    {
        if ($inspection->startup_trial_required) {
            WorkOrder::query()->whereKey($inspection->entity_id)->lockForUpdate()->firstOrFail();
        }
    }

    public function assertTrialEvidence(Inspection $inspection, ?int $quantity = null, bool $final = false): void
    {
        if (! $inspection->startup_trial_required) {
            return;
        }
        $workOrder = WorkOrder::query()->find($inspection->entity_id);
        $output = $inspection->trialOutput()->first();
        $required = min((int) $inspection->sample_size, (int) $workOrder?->quantity_target);
        if ($inspection->entity_type !== InspectionEntityType::WorkOrder || $inspection->stage !== InspectionStage::InProcess
            || $inspection->wo_operation_id !== null || ! $output || $required <= 0
            || (int) $output->work_order_id !== (int) $inspection->entity_id || $output->trial_quantity !== $required) {
            throw new BusinessRuleException("Waiting for Production to record {$required} trial pieces before QC evidence can be saved.");
        }
        $quantity = $quantity ?? ($final ? $inspection->inspected_quantity : null);
        if (($quantity !== null && ($quantity < 1 || $quantity > $output->trial_quantity))
            || ($final && $quantity !== $output->trial_quantity)) {
            throw new BusinessRuleException("Final trial inspected quantity must equal the {$output->trial_quantity} recorded sample pieces.");
        }
    }

    public function settleTrial(Inspection $inspection, User $by, int $good, array $defects = []): void
    {
        if (! $inspection->startup_trial_required) {
            return;
        }
        abort_unless($by->is_active, 403);
        DB::transaction(function () use ($inspection, $by, $good, $defects): void {
            $this->lockTrialSource($inspection);
            $inspection = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);
            $workOrder = WorkOrder::query()->findOrFail($inspection->entity_id);
            $output = $inspection->trialOutput()->lockForUpdate()->firstOrFail();
            $this->assertTrialEvidence($inspection, $output->trial_quantity, true);
            $reject = $output->trial_quantity - $good;
            $canonical = [];
            foreach ($defects as $defect) {
                if (filter_var($defect['count'] ?? null, FILTER_VALIDATE_INT) === false || (int) $defect['count'] < 1
                    || ! DefectType::query()->whereKey($defect['defect_type_id'] ?? 0)->where('is_active', true)->exists()) {
                    throw new BusinessRuleException('Trial scrap requires positive whole defect counts and active defect types.');
                }
                $canonical[] = ['defect_type_id' => (int) $defect['defect_type_id'], 'count' => (int) $defect['count']];
            }
            usort($canonical, static fn (array $left, array $right): int => $left['defect_type_id'] <=> $right['defect_type_id']);
            if ($good < 0 || $reject < 0 || array_sum(array_column($canonical, 'count')) !== $reject
                || count(array_unique(array_column($canonical, 'defect_type_id'))) !== count($canonical)) {
                throw new BusinessRuleException('Trial good and defect-classified scrap must exactly equal the recorded sample quantity.');
            }
            if ($output->trial_settled_at !== null) {
                $existing = $output->defects()->orderBy('defect_type_id')->get(['defect_type_id', 'count'])->toArray();
                if ($output->good_count !== $good || $existing !== $canonical) {
                    throw new BusinessRuleException('Settled trial disposition is immutable.');
                }

                return;
            }
            if ($inspection->status === InspectionStatus::Passed) {
                if ($good !== $output->trial_quantity || $reject !== 0) {
                    throw new BusinessRuleException('A passed startup trial accepts the complete recorded sample.');
                }
            } elseif ($inspection->status === InspectionStatus::Failed) {
                $ncr = $inspection->ncr()->where('status', '!=', 'cancelled')->first();
                if (! $ncr || ! $ncr->sorting_approved_at || ! $ncr->sorting_evidence_reference
                    || (int) $ncr->sorting_approved_by !== (int) $by->id
                    || (int) $ncr->sorting_performed_by === (int) $by->id
                    || (int) $inspection->inspector_id === (int) $by->id || $inspection->hasResultAuthor($by)
                    || bccomp((string) $ncr->sorting_inspected_quantity, (string) $output->trial_quantity, 3) !== 0
                    || bccomp((string) $ncr->mrb_accepted_quantity, (string) $good, 3) !== 0) {
                    throw new BusinessRuleException('Independent approved trial recovery must cover the actual sample and kept-good quantity.');
                }
            } else {
                throw new BusinessRuleException('Approve startup QC or independent trial recovery before sample disposition.');
            }
            $output->forceFill([
                'good_count' => $good, 'reject_count' => $reject, 'trial_settled_at' => now(),
                'production_receipt_handoff_status' => $good > 0 ? ProductionReceiptHandoffStatus::NotStarted->value : ProductionReceiptHandoffStatus::NotRequired->value,
                'production_receipt_handoff_at' => now(),
            ])->save();
            foreach ($canonical as $defect) {
                $output->defects()->create($defect);
            }
            $rejected = (int) $workOrder->quantity_rejected + $reject;
            $workOrder->update([
                'quantity_good' => (int) $workOrder->quantity_good + $good, 'quantity_rejected' => $rejected,
                'scrap_rate' => $workOrder->quantity_produced > 0 ? round($rejected / $workOrder->quantity_produced * 100, 2) : 0,
            ]);
            if ($good > 0) {
                try {
                    $this->createProductionReceipt($output, $workOrder, (int) $by->id);
                } catch (ProductionReceiptHandoffException|BusinessRuleException|InvalidMovementException $error) {
                    $this->markProductionReceiptManual($output->id);
                    app(OutboxService::class)->recordForChain(new ProductionReceiptRequested($output->fresh(),
                        $error instanceof ProductionReceiptHandoffException ? $error->reasonCode : 'production_receipt_business_rule'),
                        $workOrder, 'production', 'work_order', 'production_receipt', 'production-receipt-request:'.$output->id);
                }
            }
            app(OutboxService::class)->record(new WorkOrderOutputRecorded($workOrder->fresh(), $output->fresh()->load('defects.defectType')));
        });
        ProductionDashboardService::forgetCache();
    }

    public function trialState(WorkOrder $wo): ?array
    {
        $inspection = Inspection::query()->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->where('entity_id', $wo->id)->where('stage', InspectionStage::InProcess->value)
            ->whereNull('wo_operation_id')->where('startup_trial_required', true)
            ->where('status', '!=', InspectionStatus::Cancelled->value)->orderByDesc('id')->first();
        if (! $inspection) {
            return null;
        }
        $output = $inspection->trialOutput()->first();

        return [
            'inspection_id' => $inspection->hash_id, 'sample_size' => min((int) $inspection->sample_size, (int) $wo->quantity_target),
            'recorded_quantity' => (int) $output?->trial_quantity, 'settled' => $output?->trial_settled_at !== null,
            'state' => $output?->trial_settled_at !== null ? 'released'
                : ($inspection->status === InspectionStatus::Failed ? 'failed' : ($output ? 'awaiting_qc' : 'awaiting_samples')),
            'captured_at' => $output?->recorded_at?->toIso8601String(), 'output_id' => $output?->hash_id,
        ];
    }

    /**
     * @param  array  $data  {
     *                       good_count: int, reject_count: int, shift?: string, remarks?: string,
     *                       defects?: array<int, array{defect_type_id: int, count: int}>
     *                       }
     */
    public function record(
        WorkOrder $wo,
        array $data,
        int $recordedBy,
        ?string $idempotencyKey = null,
        ?int $reworkNcrId = null,
    ): WorkOrderOutput {
        $actor = User::query()->findOrFail($recordedBy);
        abort_unless($actor->is_active, 403);
        if (! $actor->hasPermission('production.wo.record')) {
            app(WorkOrderAssignmentService::class)->internal($actor);
        }
        if ($idempotencyKey !== null && strlen($idempotencyKey) > 128) {
            throw new BusinessRuleException('The idempotency key must be 128 characters or fewer.');
        }

        $finishFirst = $wo->usesFinishFirstQc() && $reworkNcrId === null;
        if ($finishFirst) {
            if (filter_var($data['produced_count'] ?? null, FILTER_VALIDATE_INT) === false || (int) $data['produced_count'] <= 0
                || array_key_exists('good_count', $data) || array_key_exists('reject_count', $data) || array_key_exists('defects', $data)) {
                throw new BusinessRuleException('Finish-first output requires one positive whole produced_count and no QC classification fields.');
            }
            $good = (int) $data['produced_count'];
            $reject = 0;
        } else {
            if (array_key_exists('produced_count', $data)) {
                throw new BusinessRuleException('produced_count is supported only for finish-first work orders.');
            }
            foreach (['good_count', 'reject_count'] as $field) {
                if (filter_var($data[$field] ?? null, FILTER_VALIDATE_INT) === false) {
                    throw new BusinessRuleException('Good count and Reject count must be whole numbers.');
                }
            }
            $good = (int) ($data['good_count'] ?? 0);
            $reject = (int) ($data['reject_count'] ?? 0);
        }

        $idempotencyKey = $idempotencyKey !== '' ? $idempotencyKey : null;
        $total = $good + $reject;

        // Piece counts are never negative. The `$total <= 0` check below cannot
        // stand in for this: good=-5 / reject=10 sums to 5, cleared that guard,
        // and persisted quantity_good = -5 with scrap_rate = 200.00 — a corrupt
        // production record that then flows into OEE quality, the scrap rate and
        // Pareto defect analysis. RecordOutputRequest already rejects negatives
        // on the HTTP path, but record() is this module's published contract for
        // every other output path, so the guard belongs here too.
        if ($good < 0 || $reject < 0) {
            throw new BusinessRuleException('Good count and Reject count cannot be negative.');
        }

        if ($total <= 0) {
            throw new BusinessRuleException('At least one of Good count or Reject count must be greater than zero.');
        }

        $defects = $data['defects'] ?? [];
        $defectSum = 0;
        $uniqueDefectTypes = [];
        $canonicalDefects = [];

        foreach ($defects as $d) {
            if (filter_var($d['count'] ?? null, FILTER_VALIDATE_INT) === false || (int) $d['count'] < 1) {
                throw new BusinessRuleException('Defect counts must be positive whole numbers.');
            }
            $defectTypeId = (int) ($d['defect_type_id'] ?? 0);
            $count = (int) ($d['count'] ?? 0);
            $canonicalDefects[] = [
                'defect_type_id' => $defectTypeId,
                'count' => $count,
            ];
            if ($count > 0) {
                $defectSum += $count;
                $uniqueDefectTypes[] = $defectTypeId;
            }
        }

        if ($reject > 0 && $defectSum !== $reject) {
            throw new BusinessRuleException("The total sum of defects ({$defectSum}) must exactly equal the Reject count ({$reject}).");
        }

        if ($reject === 0 && $defectSum > 0) {
            throw new BusinessRuleException('Defect rows require a positive Reject count.');
        }

        if (count(array_unique($uniqueDefectTypes)) !== count($uniqueDefectTypes)) {
            throw new BusinessRuleException('Duplicate defect types are not allowed.');
        }

        usort($canonicalDefects, static function (array $left, array $right): int {
            return $left['defect_type_id'] <=> $right['defect_type_id']
                ?: $left['count'] <=> $right['count'];
        });

        $idempotencyFingerprint = $this->idempotencyFingerprint(
            $good,
            $reject,
            $data['shift'] ?? null,
            $data['remarks'] ?? null,
            $canonicalDefects,
        );
        if ($reworkNcrId !== null) {
            $idempotencyFingerprint = hash('sha256', $idempotencyFingerprint.':repair:'.$reworkNcrId);
        }
        if (isset($data['crew_id'])) {
            $idempotencyFingerprint = hash('sha256', $idempotencyFingerprint.':actor:'.$recordedBy.':crew:'.$data['crew_id'].':reason:'.($data['recording_reason'] ?? ''));
        }
        if (array_key_exists('material_handover_acknowledged', $data) || array_key_exists('material_handover_reason', $data)) {
            $idempotencyFingerprint = hash('sha256', $idempotencyFingerprint.':handover:'.json_encode([
                'acknowledged' => $data['material_handover_acknowledged'] ?? null,
                'reason' => $data['material_handover_reason'] ?? null,
            ], JSON_THROW_ON_ERROR));
        }

        // Cache is performance-only: every hit is validated against the
        // durable output row and its persisted fingerprint.
        if ($idempotencyKey !== null) {
            $cacheKey = "production:idem:{$wo->id}:{$idempotencyKey}";
            if (($outputId = Cache::get($cacheKey)) !== null) {
                $cached = WorkOrderOutput::query()
                    ->with('defects.defectType')
                    ->where('work_order_id', $wo->id)
                    ->find($outputId);
                if ($cached) {
                    if ((int) $cached->recorded_by !== $recordedBy || $cached->idempotency_fingerprint !== $idempotencyFingerprint) {
                        throw new BusinessRuleException('The idempotency key was already used for a different production output payload.');
                    }

                    return $cached;
                }
            }
        }

        $output = DB::transaction(function () use (
            $wo,
            $data,
            $recordedBy,
            $good,
            $reject,
            $total,
            $defects,
            $idempotencyKey,
            $idempotencyFingerprint,
            $reworkNcrId,
            $actor,
            $finishFirst,
        ): WorkOrderOutput {
            // Lock + reload the WO so concurrent recordings don't lose increments.
            $fresh = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $fresh) {
                throw new BusinessRuleException('The work order no longer exists.');
            }
            if (($fresh->usesFinishFirstQc() && $reworkNcrId === null) !== $finishFirst) {
                throw new BusinessRuleException('The work order QC workflow changed. Reload before recording output.');
            }

            // Check the durable key before the status guard: a valid retry
            // replays even after the WO has become terminal.
            if ($idempotencyKey !== null) {
                $existing = WorkOrderOutput::query()
                    ->where('work_order_id', $fresh->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ((int) $existing->recorded_by !== $recordedBy || $existing->idempotency_fingerprint !== $idempotencyFingerprint) {
                        throw new BusinessRuleException('The idempotency key was already used for a different production output payload.');
                    }

                    return $existing->load('defects.defectType');
                }
            }

            // Re-check the authoritative WO, not the route-bound/stale model.
            $repairNcr = $reworkNcrId !== null
                ? NonConformanceReport::query()->lockForUpdate()->findOrFail($reworkNcrId)
                : null;
            if ($repairNcr) {
                app(WorkOrderReworkService::class)->assertRepair($fresh, $repairNcr);
                if (! $repairNcr->rework_started_at || $repairNcr->rework_finished_at) {
                    throw new BusinessRuleException('Start repair before recording output; finished repair is frozen.');
                }
                $alreadyRecorded = $fresh->outputs()->where('rework_ncr_id', $repairNcr->id)->sum(DB::raw('good_count + reject_count'));
                if ((int) $alreadyRecorded + $total > (int) $repairNcr->confirmed_rework_quantity) {
                    throw new BusinessRuleException('Repair output cannot exceed the confirmed repair quantity.');
                }
            } elseif ($fresh->status !== WorkOrderStatus::InProgress) {
                throw new BusinessRuleException('Only in-progress work orders can record output.');
            }
            $machineId = $repairNcr?->rework_machine_id ?? (! $repairNcr ? $fresh->machine_id : null);
            $moldId = $repairNcr?->rework_mold_id ?? (! $repairNcr ? $fresh->mold_id : null);
            if (! $repairNcr && (! $machineId || ! $moldId)) {
                throw new BusinessRuleException('A standard production work order requires both a machine and mold before output can be recorded. Production management must reconcile the work order first.');
            }
            if (($machineId === null) !== ($moldId === null)) {
                throw new BusinessRuleException('Production equipment ownership must include both a machine and mold.');
            }
            if ($machineId && $moldId) {
                $machine = Machine::query()->lockForUpdate()->findOrFail($machineId);
                $mold = Mold::query()->lockForUpdate()->findOrFail($moldId);
                app(ProductionResourceService::class)->assertCanAcquire($fresh, $machine, $mold, $reworkNcrId);
                if ((int) $machine->current_work_order_id !== $fresh->id || (int) $mold->current_work_order_id !== $fresh->id) {
                    throw new BusinessRuleException('Equipment ownership must be acquired before recording new output.');
                }
            }
            $crew = app(WorkOrderAssignmentService::class)->assertCanRecord($actor, $fresh, (int) ($data['crew_id'] ?? 0), $reworkNcrId, $data['recording_reason'] ?? null);
            if ($actor->employee_id !== $crew->recorder_employee_id && trim($data['recording_reason'] ?? '') === '') {
                throw new BusinessRuleException('Explain why you are recording output on behalf of the assigned crew.');
            }
            $assignments = app(WorkOrderAssignmentService::class);
            $crewSnapshot = $assignments->serialize($crew, $assignments->schedule($fresh, $reworkNcrId)?->scheduled_start);
            $recorderSnapshot = collect($crewSnapshot['members'])->firstWhere('id', app('hashids')->encode($crew->recorder_employee_id));
            foreach ($defects as $defect) {
                if (! DefectType::query()->whereKey($defect['defect_type_id'] ?? 0)->where('is_active', true)->exists()) {
                    throw new BusinessRuleException('Select an active defect type.');
                }
            }
            $fresh->assertProcessQualityReleased();
            $handoverException = app(WorkOrderMaterialHandoverService::class)->assertForOutput($fresh, $actor, $data);
            if (! $repairNcr && $fresh->rework_source_output_id && (int) $fresh->quantity_produced + $total > (int) $fresh->quantity_target) {
                throw new BusinessRuleException('Repair output cannot exceed the confirmed pieces issued for repair.');
            }

            // The target is the GOOD quantity the order needs (MRP sizes it that
            // way). Rejects are scrap made on the way there, so they must not
            // use it up: a 150-piece WO with 3 rejects still has to deliver 150.
            if (! $repairNcr && ($finishFirst
                ? (int) $fresh->quantity_produced + $total > (int) $fresh->quantity_target
                : (int) $fresh->quantity_good + $good > (int) $fresh->quantity_target)) {
                throw new BusinessRuleException("Recording this output would exceed the work order target of {$fresh->quantity_target} pieces.");
            }

            // Reserves only cover the full-target start gate. Once production
            // is under way, actual issued material must cover the saved BOM
            // norm for cumulative good-plus-reject units. This lets a reject
            // already made be reported, while gating any further replacement
            // output until its incremental material is issued.
            if (! $repairNcr) {
                $this->materialUsage->assertProductionCoverage(
                    $fresh,
                    pendingCanonicalUnits: number_format($total, 4, '.', ''),
                );
            }

            // Generate batch code: {wo}-B{seq}.
            $existing = $fresh->outputs()->count();
            $batchCode = sprintf('%s-B%02d', $fresh->wo_number, $existing + 1);

            $output = WorkOrderOutput::create([
                'work_order_id' => $fresh->id,
                'recorded_by' => $recordedBy,
                'recorded_at' => Carbon::now(),
                'work_order_crew_id' => $crew->id,
                'crew_snapshot' => $crewSnapshot,
                'recording_reason' => $data['recording_reason'] ?? null,
                'material_handover_exception' => $handoverException,
                'good_count' => $good,
                'reject_count' => $reject,
                'shift' => $recorderSnapshot['shift']['name'] ?? null,
                'batch_code' => $batchCode,
                'remarks' => $data['remarks'] ?? null,
                'rework_ncr_id' => $repairNcr?->id,
                'rework_source_output_id' => $repairNcr?->inspection?->work_order_output_id ?? $fresh->rework_source_output_id,
                'material_lineage' => [
                    'work_order_class' => $fresh->work_order_class ?: 'standard',
                    'exception_reason' => $fresh->exception_reason,
                    'authorized_by' => $fresh->exception_authorized_by,
                    'material_plan_source' => $fresh->material_plan_source,
                    'materials' => array_map(static fn (array $usage): array => [
                        'item_id' => $usage['item_id'],
                        'bom_quantity' => $usage['required_quantity'],
                        'actual_quantity_issued' => $usage['actual_quantity_issued'],
                        'actual_cost' => $usage['actual_cost'],
                        'auto_quantity_issued' => $usage['auto_quantity_issued'],
                        'auto_actual_cost' => $usage['auto_actual_cost'],
                        'auto_gross_quantity_issued' => $usage['auto_gross_quantity_issued'],
                        'auto_gross_actual_cost' => $usage['auto_gross_actual_cost'],
                        'auto_returned_quantity' => $usage['auto_returned_quantity'],
                        'auto_returned_cost' => $usage['auto_returned_cost'],
                        'manual_quantity_issued' => $usage['manual_quantity_issued'],
                        'manual_actual_cost' => $usage['manual_actual_cost'],
                        'manual_gross_quantity_issued' => $usage['manual_gross_quantity_issued'],
                        'manual_gross_actual_cost' => $usage['manual_gross_actual_cost'],
                        'manual_returned_quantity' => $usage['manual_returned_quantity'],
                        'manual_returned_cost' => $usage['manual_returned_cost'],
                        'returned_quantity' => $usage['returned_quantity'],
                        'returned_cost' => $usage['returned_cost'],
                    ], $this->materialUsage->groups($fresh)),
                    'material_lot_references' => $fresh->material_lot_references ?? [],
                ],
                'idempotency_key' => $idempotencyKey,
                'idempotency_fingerprint' => $idempotencyFingerprint,
                'production_receipt_handoff_status' => $good > 0
                    ? ProductionReceiptHandoffStatus::NotStarted->value
                    : ProductionReceiptHandoffStatus::NotRequired->value,
                'production_receipt_handoff_at' => Carbon::now(),
            ]);

            foreach ($defects as $d) {
                if ((int) ($d['count'] ?? 0) <= 0) {
                    continue;
                }
                $output->defects()->create([
                    'defect_type_id' => (int) $d['defect_type_id'],
                    'count' => (int) $d['count'],
                ]);
            }

            if (! $repairNcr) {
                $fresh->update([
                    'quantity_produced' => (int) $fresh->quantity_produced + $total,
                    'quantity_good' => (int) $fresh->quantity_good + $good,
                    'quantity_rejected' => (int) $fresh->quantity_rejected + $reject,
                    'scrap_rate' => (int) $fresh->quantity_produced + $total > 0
                        ? round((((int) $fresh->quantity_rejected + $reject) /
                                ((int) $fresh->quantity_produced + $total)) * 100, 2)
                        : 0,
                ]);
            }

            // Bump mold shot count (may auto-flip mold→Maintenance at threshold).
            // Sprint 6 audit §1.6: lock the mold row inside this transaction
            // so concurrent output recordings on the same mold cannot lose a
            // shot (lockForUpdate matches the WO row lock taken above).
            $shotMoldId = $repairNcr?->rework_mold_id ?? (! $fresh->rework_source_output_id && ! $repairNcr ? $fresh->mold_id : null);
            if ($shotMoldId) {
                $mold = Mold::lockForUpdate()->find($shotMoldId);
                if ($mold) {
                    $this->molds->incrementShots($mold, $total);
                }
            }

            // F-04 — record ProductionReceipt for good output at an FG-zone location.
            $receiptNeedsRecovery = false;
            $receiptReasonCode = 'automatic_production_receipt_failed';
            $repairSourceId = $repairNcr?->inspection?->work_order_output_id ?? $fresh->rework_source_output_id;
            if ($good > 0 || $repairSourceId) {
                if ($repairSourceId) {
                    // Repair reclassifies existing pieces: consume their original
                    // lot before receiving the repaired lot, in this transaction.
                    $source = WorkOrderOutput::query()->lockForUpdate()->findOrFail($repairSourceId);
                    $receipt = $source->productionReceiptMovement;
                    if (! $receipt || ! $receipt->lot_number || ! $receipt->to_location_id) {
                        throw new BusinessRuleException('Repair requires the original traceable finished-goods receipt.');
                    }
                    $consumption = $this->movements->move(new StockMovementInput(
                        type: $good > 0 ? StockMovementType::AdjustmentOut : StockMovementType::Scrap,
                        itemId: (int) $receipt->item_id,
                        quantity: (string) ($good > 0 ? $good : $reject),
                        fromLocationId: app(QualityStockHoldService::class)->dispositionLocation((int) $receipt->item_id, (string) $receipt->lot_number, (string) ($good > 0 ? $good : $reject)),
                        referenceType: 'work_order_output',
                        referenceId: (int) $output->id,
                        createdBy: $recordedBy,
                        lotNumber: (string) $receipt->lot_number,
                        remarks: "Original pieces consumed for repair {$fresh->wo_number}.",
                    ));
                    if ($good > 0 && $reject > 0) {
                        $this->movements->move(new StockMovementInput(
                            type: StockMovementType::Scrap, itemId: (int) $receipt->item_id, quantity: (string) $reject,
                            fromLocationId: app(QualityStockHoldService::class)->dispositionLocation((int) $receipt->item_id, (string) $receipt->lot_number, (string) $reject),
                            referenceType: 'work_order_output', referenceId: (int) $output->id, createdBy: $recordedBy,
                            lotNumber: (string) $receipt->lot_number, remarks: "Unrepairable pieces from {$fresh->wo_number}.",
                        ));
                    }
                    $this->createProductionReceipt($output, $fresh, $recordedBy, (string) $consumption->unit_cost);
                } else {
                    try {
                        $this->createProductionReceipt($output, $fresh, $recordedBy);
                    } catch (ProductionReceiptHandoffException|BusinessRuleException|InvalidMovementException $e) {
                        $receiptNeedsRecovery = true;
                        $receiptReasonCode = $e instanceof ProductionReceiptHandoffException
                            ? $e->reasonCode
                            : 'production_receipt_business_rule';
                        $this->markProductionReceiptManual($output->id);
                        $wasRecentlyCreated = $output->wasRecentlyCreated;
                        $output = $output->fresh();
                        $output->wasRecentlyCreated = $wasRecentlyCreated;
                        Log::warning('F-04: ProductionReceipt handoff requires recovery', [
                            'wo_id' => $fresh->id,
                            'output_id' => $output->id,
                            'reason_code' => $receiptReasonCode,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $eventOutput = $output->load('defects.defectType');
            if ($repairNcr && $good > 0 && ! $fresh->usesFinishFirstQc()) {
                app(InspectionService::class)->createForOutput($output, $fresh, User::query()->findOrFail($recordedBy));
            }
            app(OutboxService::class)->record(
                new WorkOrderOutputRecorded($fresh->fresh(), $eventOutput),
            );

            if ($receiptNeedsRecovery) {
                app(OutboxService::class)->recordForChain(
                    new ProductionReceiptRequested($eventOutput, $receiptReasonCode),
                    $fresh,
                    'production',
                    'work_order',
                    'production_receipt',
                    'production-receipt-request:'.$output->id,
                );
            }

            if (! $repairNcr) {
                app(WorkOrderService::class)->autoFinishIfTargetReached($fresh);
            }

            return $eventOutput;
        });

        // Cache the durable result for fast replay. The database row remains
        // authoritative after cache expiry/flush and after WO status changes.
        if ($idempotencyKey !== null) {
            Cache::put("production:idem:{$output->work_order_id}:{$idempotencyKey}", $output->id, self::IDEMPOTENCY_TTL_SECONDS);
        }

        ProductionDashboardService::forgetCache();

        return $output;
    }

    /** @param array<int, array{defect_type_id:int, count:int}> $defects */
    private function idempotencyFingerprint(
        int $good,
        int $reject,
        mixed $shift,
        mixed $remarks,
        array $defects,
    ): string {
        return hash('sha256', json_encode([
            'good_count' => $good,
            'reject_count' => $reject,
            'shift' => $shift === null ? null : (string) $shift,
            'remarks' => $remarks === null ? null : (string) $remarks,
            'defects' => $defects,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Retry only the output → stock receipt handoff. The output and WO facts
     * are already committed, so this method never re-records production or
     * increments WO totals a second time.
     */
    public function retryProductionReceipt(WorkOrderOutput $output, User $by): WorkOrderOutput
    {
        try {
            return DB::transaction(function () use ($output, $by): WorkOrderOutput {
                $workOrder = WorkOrder::query()->whereKey($output->work_order_id)->lockForUpdate()->first();
                if ($output->trial_inspection_id !== null) {
                    Inspection::query()->whereKey($output->trial_inspection_id)->lockForUpdate()->first();
                }
                $lockedOutput = WorkOrderOutput::query()
                    ->whereKey($output->id)
                    ->lockForUpdate()
                    ->first();
                if (! $lockedOutput) {
                    throw new ProductionReceiptHandoffException(
                        'The production output no longer exists.',
                        'work_order_output_missing',
                    );
                }

                if ($lockedOutput->trial_inspection_id !== null && $lockedOutput->trial_settled_at === null) {
                    throw new BusinessRuleException('Startup trial pieces await QC disposition before finished-goods receipt.');
                }
                if ((int) $lockedOutput->good_count <= 0) {
                    $lockedOutput->forceFill([
                        'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::NotRequired->value,
                        'production_receipt_handoff_message' => null,
                        'production_receipt_handoff_at' => $lockedOutput->production_receipt_handoff_at ?? now(),
                    ])->save();

                    return $lockedOutput->fresh();
                }

                $workOrder = WorkOrder::query()
                    ->whereKey($lockedOutput->work_order_id)
                    ->lockForUpdate()
                    ->first();
                if (! $workOrder) {
                    throw new ProductionReceiptHandoffException(
                        'The parent work order no longer exists.',
                        'work_order_missing',
                    );
                }

                $receipt = $this->createProductionReceipt($lockedOutput, $workOrder, (int) $by->id);
                app(WorkOrderService::class)->requestQualityClose($workOrder->fresh(), $by);

                return $receipt;
            });
        } catch (ProductionReceiptHandoffException|BusinessRuleException|InvalidMovementException $e) {
            $this->markProductionReceiptManual($output->id);
            throw $e;
        }
    }

    /** Persist the safe operator-facing state for a failed receipt handoff. */
    public function markProductionReceiptManual(int $outputId): void
    {
        DB::transaction(function () use ($outputId): void {
            $output = WorkOrderOutput::query()->whereKey($outputId)->lockForUpdate()->first();
            if (! $output || (int) $output->good_count <= 0 || $output->production_receipt_movement_id !== null) {
                return;
            }

            $output->forceFill([
                'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::ManualRequired->value,
                'production_receipt_handoff_message' => self::RECEIPT_MANUAL_MESSAGE,
                'production_receipt_handoff_at' => now(),
            ])->save();
        });
    }

    /** @throws ProductionReceiptHandoffException|BusinessRuleException|InvalidMovementException */
    private function createProductionReceipt(
        WorkOrderOutput $output,
        WorkOrder $workOrder,
        int $createdBy,
        ?string $unitCost = null,
    ): WorkOrderOutput {
        if ((int) $output->held_quantity > 0) {
            throw new BusinessRuleException('Historical output awaits independent QC recovery before inventory receipt.');
        }
        if ($output->trial_inspection_id !== null && $output->trial_settled_at === null) {
            throw new BusinessRuleException('Startup trial pieces await QC disposition before finished-goods receipt.');
        }
        if ((int) $output->good_count <= 0) {
            $output->forceFill([
                'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::NotRequired->value,
                'production_receipt_handoff_message' => null,
                'production_receipt_handoff_at' => $output->production_receipt_handoff_at ?? now(),
            ])->save();

            return $output->fresh();
        }

        if ($output->production_receipt_movement_id !== null) {
            $linked = StockMovement::query()->find($output->production_receipt_movement_id);
            if ($linked) {
                return $this->markProductionReceiptGenerated($output, (int) $linked->id);
            }
        }

        $existing = StockMovement::query()
            ->where('movement_type', StockMovementType::ProductionReceipt->value)
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->orderBy('id')
            ->get();
        if ($existing->count() > 1) {
            throw new ProductionReceiptHandoffException(
                'More than one finished-goods receipt is linked to this production output.',
                'duplicate_production_receipts',
            );
        }
        if ($existing->count() === 1) {
            return $this->markProductionReceiptGenerated($output, (int) $existing->first()->id);
        }

        // Pre-existing installations used the parent WO as the reference. A
        // single-output/one-movement match is safe to adopt; multiple legacy
        // rows remain manual because guessing would double-count inventory.
        $legacy = StockMovement::query()
            ->where('movement_type', StockMovementType::ProductionReceipt->value)
            ->where('reference_type', 'work_order')
            ->where('reference_id', $workOrder->id)
            ->orderBy('id')
            ->get();
        $goodOutputCount = WorkOrderOutput::query()
            ->where('work_order_id', $workOrder->id)
            ->where('good_count', '>', 0)
            ->count();
        if ($legacy->count() > 0) {
            if ($legacy->count() === 1 && $goodOutputCount === 1) {
                return $this->markProductionReceiptGenerated($output, (int) $legacy->first()->id);
            }

            throw new ProductionReceiptHandoffException(
                'Legacy finished-goods receipts cannot be assigned to this output without reconciliation.',
                'legacy_production_receipt_ambiguous',
            );
        }

        $product = $workOrder->relationLoaded('product')
            ? $workOrder->product
            : $workOrder->product()->first();
        if (! $product || ! $product->part_number) {
            throw new ProductionReceiptHandoffException(
                'The work order product has no part number for finished-goods inventory.',
                'product_part_number_missing',
            );
        }

        $inventory = app(FinishedGoodInventoryService::class);
        $fgItem = $inventory->ensureItem($product);

        // Persist the selected cost before posting so recovery preserves repair and manufacturing valuation.
        $unitCost = $unitCost ?? $output->receipt_unit_cost;
        if ($unitCost === null && ($output->rework_source_output_id !== null || $workOrder->rework_source_output_id !== null)) {
            throw new ProductionReceiptHandoffException('Repair receipt requires its original lot cost; reconcile the source receipt.', 'repair_source_cost_missing');
        }
        if ($unitCost === null) {
            if ($workOrder->unit_standard_cost === null) {
                $cost = (string) $product->standard_cost;
                if (bccomp($cost, '0', 4) <= 0) {
                    throw new ProductionReceiptHandoffException('Product standard cost is missing or zero. Review it, then retry the finished-goods receipt.', 'production_cost_missing');
                }
                // Legacy WOs have no trustworthy historical BOM reference; only freeze the current valuation basis.
                $workOrder->forceFill(['unit_standard_cost' => $cost, 'cost_source' => 'product_standard_cost', 'cost_snapshot_at' => now()])->save();
            }
            $unitCost = (string) $workOrder->unit_standard_cost;
            if (bccomp($unitCost, '0', 4) <= 0) {
                throw new ProductionReceiptHandoffException('Frozen manufacturing cost must be positive before inventory receipt.', 'production_cost_missing');
            }
        }
        if ($output->receipt_unit_cost === null) {
            $output->forceFill(['receipt_unit_cost' => $unitCost])->save();
        }

        $fgLocation = $inventory->location();

        $movement = $this->movements->move(new StockMovementInput(
            type: StockMovementType::ProductionReceipt,
            itemId: $fgItem->id,
            quantity: (string) $output->good_count,
            toLocationId: $fgLocation->id,
            referenceType: 'work_order_output',
            referenceId: $output->id,
            remarks: "WO {$workOrder->wo_number} batch {$output->batch_code}",
            createdBy: $createdBy,
            lotNumber: (string) $output->batch_code,
            unitCost: $unitCost,
            bypassCountFreeze: true,
        ));

        return $this->markProductionReceiptGenerated($output, (int) $movement->id);
    }

    private function markProductionReceiptGenerated(WorkOrderOutput $output, int $movementId): WorkOrderOutput
    {
        $output->forceFill([
            'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::Generated->value,
            'production_receipt_handoff_message' => null,
            'production_receipt_handoff_at' => now(),
            'production_receipt_movement_id' => $movementId,
        ])->save();

        return $output->fresh();
    }
}
