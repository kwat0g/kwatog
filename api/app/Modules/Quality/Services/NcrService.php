<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Inventory\Services\QuarantineService;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Events\MrpReplanRequested;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderReworkService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Enums\EffectivenessStatus;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Events\OutgoingQuantityReleased;
use App\Modules\Quality\Jobs\ProcessNcrRecurrenceScan;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NcrRecurrenceScan;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Support\LotDefectCounter;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 7 — Task 61. NCR lifecycle service.
 *
 * Lifecycle:
 *   create()                 — opens NCR (auto-called from inspection failure)
 *   addAction()              — append containment/corrective/preventive entry
 *   setDisposition()         — finalises material disposition
 *   close()                  — closes; on disposition=scrap from outgoing QC
 *                              auto-creates a replacement WorkOrder; on
 *                              disposition=return_to_supplier notifies Purchasing
 *   cancel()                 — voids before closure
 */
class NcrService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = NonConformanceReport::query()->with([
            'product:id,part_number,name',
            'inspection:id,inspection_number,stage,status,entity_type,entity_id,work_order_output_id,grn_item_id,batch_quantity,accepted_quantity,sample_defect_count,sample_size,capture_method,inspected_quantity,source_quantity,source_uom_code,sampling_basis,startup_trial_required,counted_capture,inspector_id',
            'inspection.trialOutput',
            'inspection.workOrderOutput',
            'inspection.inspector:id,name,role_id',
            'creator:id,name,role_id',
            'assignee:id,name',
            'reworkWorkOrder:id,wo_number,status,quantity_target',
        ]);

        if (($filters['signal'] ?? null) === 'open') {
            $q->open();
        }

        foreach (['source', 'severity', 'status', 'disposition'] as $f) {
            if (! empty($filters[$f])) {
                $q->where($f, $filters[$f]);
            }
        }
        if (($filters['recovery_coverage'] ?? null) === 'unlinked') {
            $q->where('disposition', NcrDisposition::Rework->value)
                ->whereNull('rework_work_order_id')
                ->whereNull('replacement_work_order_id')
                ->where('status', '!=', NcrStatus::Cancelled->value);
        }
        if (! empty($filters['product_id'])) {
            $q->where('product_id', $filters['product_id']);
        }
        if (! empty($filters['inspection_id'])) {
            $q->where('inspection_id', $filters['inspection_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn (Builder $b) => $b
                ->where('ncr_number', SearchOperator::like(), $term)
                ->orWhere('defect_description', SearchOperator::like(), $term));
        }

        return $q->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function show(NonConformanceReport $ncr): NonConformanceReport
    {
        return $ncr->load([
            'product:id,part_number,name',
            'inspection:id,inspection_number,stage,status,product_id,entity_type,entity_id,work_order_output_id,grn_item_id,batch_quantity,accepted_quantity,sample_defect_count,sample_size,capture_method,inspected_quantity,source_quantity,source_uom_code,sampling_basis,startup_trial_required,counted_capture,inspector_id',
            'inspection.workOrderOutput',
            'inspection.inspector:id,name,role_id',
            'creator:id,name,role_id',
            'assignee:id,name',
            'closer:id,name',
            'mrbDecider:id,name',
            'reworkStartedBy:id,name',
            'reworkFinishedBy:id,name',
            'recurrenceOf:id,ncr_number',
            'replacementWorkOrder:id,wo_number,status,quantity_target',
            'reworkWorkOrder:id,wo_number,status,quantity_target',
            'actions' => fn ($q) => $q->with([
                'performer:id,name,role_id',
                'owner:id,name',
                'verifier:id,name',
            ])->orderBy('performed_at'),
        ]);
    }

    /**
     * @param array{
     *   source: string, severity: string, product_id?: int|null,
     *   inspection_id?: int|null, complaint_id?: int|null,
     *   defect_description: string, affected_quantity?: int,
     *   assigned_to?: int|null
     * } $data
     */
    public function create(array $data, User $by): NonConformanceReport
    {
        $ncr = DB::transaction(function () use ($data, $by) {
            if (! empty($data['inspection_id'])) {
                app(WorkOrderOutputService::class)->lockTrialSource(Inspection::query()->findOrFail($data['inspection_id']));
            }
            $inspection = ! empty($data['inspection_id'])
                ? Inspection::query()->with('measurements')->lockForUpdate()->findOrFail((int) $data['inspection_id'])
                : null;

            if ($inspection?->startup_trial_required) {
                app(WorkOrderOutputService::class)->assertTrialEvidence($inspection);
                $data['affected_quantity'] = $inspection->processRecoveryQuantity();
            }

            // One active incident per inspection; closed history remains intact.
            // The partial unique index is the concurrency backstop.
            if ($inspection !== null && isset($data['product_id']) && $data['product_id'] !== null
                && (int) $data['product_id'] !== (int) $inspection->product_id) {
                throw new BusinessRuleException('NCR product must match the linked inspection.');
            }
            if ($inspection !== null) {
                $existing = NonConformanceReport::query()
                    ->where('inspection_id', $inspection->id)
                    ->whereNotIn('status', [NcrStatus::Closed->value, NcrStatus::Cancelled->value])
                    ->first();
                if ($existing !== null) {
                    throw new BusinessRuleException(
                        "Inspection {$inspection->inspection_number} already has NCR {$existing->ncr_number}; "
                        .'resolve the active NCR before reporting another incident.'
                    );
                }
            }

            $defectSignature = (string) ($data['defect_signature'] ?? '');
            if ($defectSignature === '') {
                $defectSignature = $inspection
                    ? NcrRecurrenceDetector::signatureForInspection($inspection)
                    : NcrRecurrenceDetector::descriptionSignature((string) $data['defect_description']);
            }

            $ncr = NonConformanceReport::create([
                'ncr_number' => $this->sequences->generate('ncr'),
                'source' => NcrSource::from((string) $data['source'])->value,
                'severity' => NcrSeverity::from((string) $data['severity'])->value,
                'status' => NcrStatus::Open->value,
                'product_id' => $inspection?->product_id ?? $data['product_id'] ?? null,
                'inspection_id' => $data['inspection_id'] ?? null,
                'complaint_id' => $data['complaint_id'] ?? null,
                'defect_description' => $data['defect_description'],
                'defect_signature' => $defectSignature,
                'affected_quantity' => (int) ($data['affected_quantity'] ?? 0),
                'created_by' => $by->id,
                'assigned_to' => $data['assigned_to'] ?? null,
                'is_auto_generated' => (bool) ($data['is_auto_generated'] ?? false),
            ]);

            if ($inspection?->startup_trial_required) {
                $ncr->forceFill(['recovery_batch_quantity' => $inspection->processRecoveryQuantity()])->save();
            }
            if ($inspection && $inspection->stage === InspectionStage::Outgoing && $inspection->work_order_output_id) {
                $removed = NonConformanceReport::query()->where('inspection_id', $inspection->id)->where('id', '<', $ncr->id)->where('status', '!=', NcrStatus::Cancelled->value)->get()->sum(fn ($prior) => $prior->disposition === NcrDisposition::Scrap
                    ? max(0, (int) ($prior->recovery_batch_quantity ?? $inspection->batch_quantity) - (int) $prior->mrb_accepted_quantity)
                    : (int) $prior->confirmed_rework_quantity);
                $receipt = $inspection->workOrderOutput?->productionReceiptMovement;
                $released = $receipt?->lot_number ? (int) StockMovement::query()->where('item_id', $receipt->item_id)->where('lot_number', $receipt->lot_number)->whereIn('movement_type', ['delivery', 'material_issue'])->sum('quantity') : 0;
                $remaining = max(0, (int) $inspection->batch_quantity - $removed - $released);
                if ($remaining === 0) {
                    throw new BusinessRuleException('No pieces remain in this original batch. Report defects against the repaired output or customer return instead.');
                }
                $ncr->forceFill(['original_accepted_quantity' => (int) $inspection->accepted_quantity, 'recovery_batch_quantity' => $remaining, 'previously_released_quantity' => $released])->save();
                $inspection->forceFill(['accepted_quantity' => 0])->save();
            }

            if ($inspection?->stage === InspectionStage::Incoming && $inspection->grn_item_id) {
                $line = GrnItem::query()->with('grn', 'item')->findOrFail($inspection->grn_item_id);
                if (in_array($line->grn->status, GrnStatus::billable(), true)) {
                    $ncr->forceFill(['is_late_incoming' => true])->save();
                    $this->holdAcceptedReceipt($ncr, $line, $by);
                }
            }

            NcrRecurrenceScan::create([
                'ncr_id' => $ncr->id,
                'status' => 'pending',
                'available_at' => now(),
            ]);

            DB::afterCommit(fn () => ProcessNcrRecurrenceScan::dispatch($ncr->id));

            return $ncr->fresh();
        });

        if ($ncr->is_late_incoming) {
            $audience = User::query()->where('is_active', true)->whereHas('role', fn ($role) => $role->whereIn('slug', ['qc_inspector', 'warehouse_staff', 'purchasing_officer']))->get()
                ->filter(fn (User $user) => $user->hasPermission('quality.ncr.view'));
            app(NotificationService::class)->sendForSubject($ncr, $audience, 'quality.late_incoming', [
                'title' => 'Accepted material held: '.$ncr->ncr_number,
                'message' => 'Review remaining stock and downstream material usage. Resolve all linked quarantine holds before closing the defect.',
                'link_to' => '/quality/ncrs/'.$ncr->hash_id, 'entity_type' => 'ncr', 'entity_id' => $ncr->hash_id,
            ], 'late-incoming-'.$ncr->id, emailEnabled: false);
        }

        return $ncr;
    }

    /** Preserve passed receipt evidence while holding a newly suspect remaining lot. */
    /** Retry only missing holds; retain the original incident quantity and released history. */
    public function retryQuarantine(NonConformanceReport $ncr, User $by): NonConformanceReport
    {
        if (! $by->can('inventory.mrb.manage') || ! $by->can('quality.ncr.view')) {
            throw new BusinessRuleException('Quarantine recovery requires Quality record access and quarantine management permission.');
        }

        return DB::transaction(function () use ($ncr, $by) {
            $inspection = Inspection::query()->lockForUpdate()->findOrFail($ncr->inspection_id);
            $locked = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->id);
            $line = $inspection->grnItem;
            if (! $locked->is_late_incoming || ! in_array($locked->status, [NcrStatus::Open, NcrStatus::InProgress], true)
                || ! $line || ! in_array($line->grn->status, [GrnStatus::Accepted, GrnStatus::PartialAccepted], true)) {
                throw new BusinessRuleException('Only an active late incoming incident on accepted material can retry quarantine.');
            }
            $this->holdAcceptedReceipt($locked, $line, $by, retry: true);

            return $this->show($locked);
        });
    }

    private function holdAcceptedReceipt(NonConformanceReport $ncr, GrnItem $line, User $by, bool $retry = false): void
    {
        $levels = StockLevel::query()->where('item_id', $line->item_id)->with('location.zone.warehouse')->orderBy('location_id')->get();
        $remaining = '0.000';
        foreach ($levels as $level) {
            if (! in_array($level->location?->zone?->zone_type?->value, ['quarantine', 'scrap'], true)) {
                $quantity = $line->material_lot_number
                    ? app(StockLocationSummaryService::class)->lotQuantity($line->item_id, $level->location_id, $line->material_lot_number)
                    : ($level->location_id === $line->location_id ? (string) $level->quantity : '0');
                $remaining = bcadd($remaining, $quantity, 3);
            }
        }
        if (! $retry) {
            $ncr->forceFill(['late_hold_quantity' => $remaining])->save();
        }
        $outstanding = bcsub((string) $ncr->late_hold_quantity, (string) MaterialReviewRecord::where('ncr_id', $ncr->id)->sum('quantity'), 3);
        foreach ($levels as $level) {
            $zone = $level->location?->zone?->zone_type?->value;
            if (in_array($zone, ['quarantine', 'scrap'], true)) {
                continue;
            }
            $quantity = $line->material_lot_number
                ? app(StockLocationSummaryService::class)->lotQuantity($line->item_id, $level->location_id, $line->material_lot_number)
                : ($level->location_id === $line->location_id ? (string) $level->quantity : '0');
            if ($retry) {
                $releasedHere = (string) MaterialReviewRecord::where('ncr_id', $ncr->id)->where('status', 'released')
                    ->where('release_location_id', $level->location_id)->sum('quantity');
                $quantity = bcsub($quantity, $releasedHere, 3);
            }
            $quantity = bccomp($quantity, $outstanding, 3) > 0 ? $outstanding : $quantity;
            if (bccomp($quantity, '0', 3) <= 0) {
                continue;
            }
            try {
                app(QuarantineService::class)->hold([
                    'item_id' => $line->item_id, 'quantity' => $quantity, 'source_location_id' => $level->location_id,
                    'ncr_id' => $ncr->id, 'inspection_id' => $ncr->inspection_id,
                    'lot_number' => $line->material_lot_number, 'notes' => 'Late defect on accepted receipt '.$line->grn->grn_number,
                ], $by, 'late-incoming-'.$ncr->id.'-'.$level->location_id);
                $outstanding = bcsub($outstanding, $quantity, 3);
            } catch (BusinessRuleException $error) {
                if ($retry) {
                    throw $error;
                }
                // The source-linked NCR remains an immediate logical hold when a
                // physical move needs reservation release or quarantine setup.
                Log::warning('Late incoming quarantine needs action', ['ncr_id' => $ncr->id, 'message' => $error->getMessage()]);
            }
        }
        if ($retry && bccomp($outstanding, '0', 3) > 0) {
            throw new BusinessRuleException('Some suspect material cannot be located for quarantine. Review its stock movements and downstream traceability.');
        }
    }

    /**
     * Auto-open an NCR from a failed inspection. Idempotent: returns the
     * existing NCR if one is already linked. Severity is derived from
     * critical-fail count and Ac overflow.
     */
    public function openFromInspectionFailure(Inspection $inspection, User $by): NonConformanceReport
    {
        $existing = NonConformanceReport::query()
            ->where('inspection_id', $inspection->id)
            ->first();
        if ($existing) {
            return $this->show($existing);
        }

        $inspection->loadMissing('measurements');
        $criticalFail = $inspection->critical_defect || ($inspection->usesChecklistCapture()
            ? $inspection->measurements->contains(fn ($row) => $row->is_critical && $row->item_result === 'failed')
            : LotDefectCounter::for($inspection, $inspection->measurements)['criticalFail']);
        $severity = $criticalFail
            ? NcrSeverity::Critical->value
            : ($inspection->defect_count > $inspection->accept_count
                ? NcrSeverity::High->value
                : NcrSeverity::Medium->value);

        // Build description: for lot_checklist, mention the defect count found in sample.
        $descriptionBase = 'Automated NCR from inspection '.$inspection->inspection_number.': ';
        if ($inspection->usesFullPieceCapture()) {
            $descriptionBase .= $inspection->sample_defect_count.' defective piece(s) found while checking all '.$inspection->batch_quantity.' pieces';
        } elseif ($inspection->inspection_mode && $inspection->inspection_mode->value === 'lot_checklist' && $inspection->sample_defect_count !== null) {
            $descriptionBase .= $inspection->sample_defect_count.' defective piece(s) found in a sample of '
                              .$inspection->sample_size.' (accept ≤ '.$inspection->accept_count.')';
        } else {
            $descriptionBase .= $inspection->defect_count.' defect(s)';
        }
        $descriptionBase .= ' on '.$inspection->stage->value.' stage'
                          .($criticalFail ? ' (critical parameter failure)' : '').'.';
        if ($inspection->recorded_result && $inspection->problem_description) {
            $descriptionBase = 'Reported problem from inspection '.$inspection->inspection_number.': '.$inspection->problem_description;
        }

        try {
            $ncr = $this->create([
                'source' => NcrSource::InspectionFail->value,
                'severity' => $severity,
                'product_id' => $inspection->product_id,
                'inspection_id' => $inspection->id,
                'defect_description' => $descriptionBase,
                'affected_quantity' => $inspection->affected_quantity ?? $inspection->batch_quantity,
                'is_auto_generated' => true,
            ], $by);
        } catch (QueryException $exception) {
            if (! $this->isInspectionUniqueViolation($exception)) {
                throw $exception;
            }

            $winner = NonConformanceReport::query()
                ->where('inspection_id', $inspection->id)
                ->first();
            if (! $winner) {
                throw $exception;
            }

            return $this->show($winner);
        }

        // Task A6 — notify QC Head so root cause / disposition can be filled.
        try {
            $notificationRoles = array_values(array_filter(
                (array) $this->settings->get('quality.ncr.auto_created_notification_roles', []),
                static fn ($role): bool => is_string($role) && $role !== '',
            ));
            $qcHeads = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $notificationRoles))
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user): bool => $user->hasPermission('quality.ncr.view'));
            $inspectionStage = $inspection->stage->label();
            $severityLabel = ucfirst($severity);
            app(NotificationService::class)->sendForSubject($ncr, $qcHeads, 'auto_ncr_created', [
                'title' => 'NCR auto-created',
                'message' => $inspection->problem_description
                    ? "NCR auto-created from {$inspectionStage} inspection. Reported problem: {$inspection->problem_description}. Review disposition."
                    : "NCR auto-created from {$inspectionStage} inspection. {$inspection->defect_count} pcs rejected. {$severityLabel} severity. Review disposition.",
                'link_to' => "/quality/ncrs/{$ncr->hash_id}",
                'entity_type' => 'ncr',
                'entity_id' => $ncr->hash_id,
                'ncr_number' => $ncr->ncr_number,
                'inspection_id' => $inspection->hash_id,
                'severity' => $severity,
            ]);
        } catch (\Throwable $e) {
            // NCR is already persisted; only the QC notification fan-out failed.
            Log::warning(
                "NcrService: failed to notify on NCR {$ncr->ncr_number}: {$e->getMessage()}",
                ['ncr_id' => $ncr->id, 'exception' => $e::class]
            );
        }

        return $ncr;
    }

    public function addAction(NonConformanceReport $ncr, array $data, User $by): NcrAction
    {
        if ($ncr->status->isTerminal()) {
            throw new BusinessRuleException('Cannot add actions to a closed NCR.');
        }

        return DB::transaction(function () use ($ncr, $data, $by) {
            // Lock-then-guard: re-read so a stale action cannot be appended to
            // an NCR that a concurrent close just made terminal.
            $sourceInspection = $ncr->inspection_id ? Inspection::query()->find($ncr->inspection_id) : null;
            if ($sourceInspection?->startup_trial_required) {
                app(WorkOrderOutputService::class)->lockTrialSource($sourceInspection);
                Inspection::query()->whereKey($sourceInspection->id)->lockForUpdate()->firstOrFail();
            }
            if ($sourceInspection?->usesFullPieceCapture()) {
                WorkOrder::query()->whereKey($sourceInspection->entity_id)->lockForUpdate()->firstOrFail();
                WorkOrderOutput::query()->where('work_order_id', $sourceInspection->entity_id)->orderBy('id')->lockForUpdate()->get();
                Inspection::query()->whereKey($sourceInspection->id)->lockForUpdate()->firstOrFail();
            }
            $locked = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->getKey());
            if ($locked->status->isTerminal()) {
                throw new BusinessRuleException('Cannot add actions to a closed NCR.');
            }
            $action = NcrAction::create([
                'ncr_id' => $locked->id,
                'action_type' => NcrActionType::from((string) $data['action_type'])->value,
                'description' => $data['description'],
                'performed_by' => $by->id,
                'performed_at' => $data['performed_at'] ?? now(),
                // CAPA: seed ownership + due date so the effectiveness loop has
                // an accountable owner once the NCR closes.
                'owner_id' => $data['owner_id'] ?? $by->id,
                'due_date' => $data['due_date'] ?? now()->addDays($this->settings->requiredInt('quality.effectiveness.check_interval_days', 1))->toDateString(),
            ]);
            // Bump status to in_progress on first action.
            if ($locked->status === NcrStatus::Open) {
                $locked->forceFill(['status' => NcrStatus::InProgress->value])->save();
            }

            return $action->load(['performer:id,name,role_id', 'owner:id,name']);
        });
    }

    /**
     * Set NCR disposition.
     *
     * For a failed incoming (GRN) inspection with MRB review on, this IS the
     * Material Review Board decision: it is recorded with its decider and the
     * receipt is settled in the same transaction (see GrnService::settleIncomingQc).
     * use_as_is is a concession, so the inspector who failed the lot cannot
     * grant it. A sorted-good quantity requires full sorting and reinspection
     * evidence, approved by someone other than the sorter and original makers.
     * Outgoing recovery changes only the releasable quantity; the original
     * failed inspection and its evidence remain unchanged.
     *
     * @param  array{inspected_quantity?: mixed, evidence_reference?: mixed, performed_by?: mixed, recovery_passed?: mixed}  $sortingEvidence
     */
    public function setDisposition(
        NonConformanceReport $ncr,
        string $disposition,
        ?string $rootCause,
        ?string $correctiveAction,
        ?User $by = null,
        ?string $mrbAcceptedQuantity = null,
        array $sortingEvidence = [],
    ): NonConformanceReport {
        if ($ncr->status->isTerminal()) {
            throw new BusinessRuleException('NCR is already closed.');
        }
        $dispositionEnum = NcrDisposition::from($disposition);

        return DB::transaction(function () use ($ncr, $dispositionEnum, $rootCause, $correctiveAction, $by, $mrbAcceptedQuantity, $sortingEvidence) {
            // Lock-then-guard: a stale disposition must not flip a concurrently closed NCR back to in_progress.
            $sourceInspection = $ncr->inspection_id ? Inspection::query()->find($ncr->inspection_id) : null;
            if ($sourceInspection?->startup_trial_required) {
                app(WorkOrderOutputService::class)->lockTrialSource($sourceInspection);
                Inspection::query()->whereKey($sourceInspection->id)->lockForUpdate()->firstOrFail();
            }
            $locked = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->getKey());
            if ($locked->status->isTerminal()) {
                throw new BusinessRuleException('NCR is already closed.');
            }

            // The MRB decision settled the receipt (stock in, remainder rejected).
            // A different disposition afterwards would contradict what inventory
            // already did; root cause / corrective action stay editable.
            $currentDisposition = $locked->disposition instanceof NcrDisposition
                ? $locked->disposition->value
                : $locked->disposition;
            if ($locked->mrb_decided_at !== null
                && ($currentDisposition !== $dispositionEnum->value || $mrbAcceptedQuantity !== null || $sortingEvidence !== [])) {
                throw new BusinessRuleException(
                    'The Material Review Board decision is final: material quantities were settled against it.'
                );
            }

            $inspection = $locked->inspection_id
                ? Inspection::query()->with('measurements')->lockForUpdate()->findOrFail($locked->inspection_id)
                : null;
            $locked->setRelation('inspection', $inspection);
            if ($dispositionEnum === NcrDisposition::UseAsIs
                && ($locked->severity === NcrSeverity::Critical
                    || ($inspection && LotDefectCounter::for($inspection, $inspection->measurements)['criticalFail']))) {
                throw new BusinessRuleException('A critical failure cannot be released by a use-as-is concession.');
            }
            $grn = $locked->mrb_decided_at === null ? $this->pendingIncomingGrnForMrb($locked) : null;
            $mrbQuantity = $grn !== null
                ? $this->validateMrbDecision($locked, $grn, $dispositionEnum, $by, $mrbAcceptedQuantity)
                : null;
            $outgoingRecovery = $grn === null && $mrbAcceptedQuantity !== null
                && $inspection?->stage === InspectionStage::Outgoing && $inspection->work_order_output_id !== null;
            $processRecovery = $grn === null && $mrbAcceptedQuantity !== null
                && $inspection?->stage === InspectionStage::InProcess && $dispositionEnum === NcrDisposition::Rework;
            $sortingFill = [];
            $trialDefects = [];
            if ($processRecovery) {
                if ($inspection->startup_trial_required || ($inspection->work_order_output_id && $inspection->workOrderOutput?->production_started_at)) {
                    app(WorkOrderOutputService::class)->assertTrialEvidence($inspection, null, true);
                    if (! $by?->is_active || ! $by->hasPermission('quality.ncr.manage')) {
                        throw new BusinessRuleException('Trial recovery requires an active authorized NCR approver.');
                    }
                    $scrap = $sortingEvidence['trial_scrapped_quantity'] ?? null;
                    $trialDefects = $sortingEvidence['trial_defects'] ?? null;
                    if (! is_int($scrap) || $scrap < 0 || ! is_array($trialDefects)
                        || ! preg_match('/^\d+(?:\.0{1,3})?$/', $mrbAcceptedQuantity)
                        || (int) $mrbAcceptedQuantity + $scrap !== $inspection->processRecoveryQuantity()
                        || array_sum(array_column($trialDefects, 'count')) !== $scrap) {
                        throw new BusinessRuleException('Trial recovery needs explicit whole kept-good and scrap quantities plus actual defect counts covering every sample piece.');
                    }
                    if (trim((string) ($rootCause ?: $locked->root_cause)) === ''
                        || trim((string) ($correctiveAction ?: $locked->corrective_action)) === '') {
                        throw new BusinessRuleException('Record the verified process correction and its root cause before trial recovery.');
                    }
                } elseif (bccomp($mrbAcceptedQuantity, (string) $inspection->processRecoveryQuantity(), 3) !== 0) {
                    throw new BusinessRuleException('In-process recovery must verify the corrected process on the inspected sample before production continues.');
                }
                $mrbQuantity = $mrbAcceptedQuantity;
                $sortingFill = $this->validateSortingEvidence($inspection, (string) $inspection->processRecoveryQuantity(), $by, $sortingEvidence);
            } elseif ($outgoingRecovery) {
                $mrbQuantity = $this->validateOutgoingRecovery($inspection, $dispositionEnum, $mrbAcceptedQuantity);
                $limit = (int) ($locked->recovery_batch_quantity ?? $inspection->batch_quantity);
                if (bccomp($mrbQuantity, (string) $limit, 3) > 0) {
                    throw new BusinessRuleException('Good quantity cannot include pieces already repaired or scrapped.');
                }
                $sortingFill = $this->validateSortingEvidence($inspection, (string) $limit, $by, $sortingEvidence);
            } elseif ($grn === null && $mrbAcceptedQuantity !== null) {
                throw new BusinessRuleException(
                    'Good pieces kept after sorting require a pending incoming receipt or a failed output-bound outgoing inspection.'
                );
            }
            if ($grn !== null && ($dispositionEnum === NcrDisposition::UseAsIs
                || ($mrbQuantity !== null && bccomp($mrbQuantity, '0', 3) > 0))) {
                if ($inspection->grn_item_id === null && $grn->items()->count() > 1) {
                    throw new BusinessRuleException('Sorting and release evidence must cover one receipt line at a time.');
                }
                $line = $inspection->grn_item_id
                    ? GrnItem::query()->where('goods_receipt_note_id', $grn->id)->findOrFail($inspection->grn_item_id)
                    : $grn->items()->firstOrFail();
                $sortingFill = $this->validateSortingEvidence($inspection, (string) $line->quantity_received, $by, $sortingEvidence);
            } elseif (! $outgoingRecovery && ! $processRecovery && $sortingEvidence !== []) {
                throw new BusinessRuleException('Sorting evidence applies only to a material release decision.');
            }

            if ($dispositionEnum === NcrDisposition::UseAsIs && $locked->mrb_decided_at === null && $sortingFill !== []) {
                $approval = trim((string) ($sortingEvidence['concession_approval_reference'] ?? ''));
                if ($approval === '' || mb_strlen($approval) > 500 || $approval === ($sortingFill['sorting_evidence_reference'] ?? null)) {
                    throw new BusinessRuleException('A known-defect concession requires a separate authorized approval or customer sign-off reference.');
                }
                $sortingFill['concession_approval_reference'] = $approval;
            }

            $fill = [
                'disposition' => $dispositionEnum->value,
                'root_cause' => $rootCause ?: $locked->root_cause,
                'corrective_action' => $correctiveAction ?: $locked->corrective_action,
                'status' => NcrStatus::InProgress->value,
            ];
            if ($grn !== null || $outgoingRecovery || $processRecovery) {
                $fill['mrb_accepted_quantity'] = $mrbQuantity;
                $fill['mrb_decided_by'] = $by->id;
                $fill['mrb_decided_at'] = now();
            }
            $locked->forceFill($fill + $sortingFill)->save();
            if ($inspection?->stage === InspectionStage::Outgoing
                && $dispositionEnum === NcrDisposition::Rework && $locked->rework_work_order_id === null) {
                if (! $outgoingRecovery || $locked->sorting_approved_at === null) {
                    throw new BusinessRuleException('Confirm full sorting and the good quantity before creating a repair work order.');
                }
                $repairQuantity = max(0, (int) ($locked->recovery_batch_quantity ?? $inspection->batch_quantity) - (int) $mrbQuantity);

                $locked->forceFill(['confirmed_rework_quantity' => $repairQuantity])->save();
                if ($repairQuantity > 0) {
                    $sourceWo = WorkOrder::query()->lockForUpdate()->findOrFail($inspection->entity_id);
                    $repair = ! in_array($sourceWo->status->value, ['closed', 'cancelled'], true)
                        ? $sourceWo
                        : $this->createRequiredWorkOrder([
                            'product_id' => $inspection->product_id,
                            'quantity_target' => $repairQuantity,
                            'priority' => $this->settings->requiredInt('quality.ncr.replacement_work_order_priority', 0, 10),
                            'planned_start' => now()->toDateString(),
                            'planned_end' => now()->addDay()->toDateString(),
                            'parent_ncr_id' => $locked->id,
                            'rework_source_output_id' => $inspection->work_order_output_id,
                            'work_order_class' => 'service',
                            'exception_reason' => "Repair held pieces from NCR {$locked->ncr_number}.",
                            'created_by' => $by->id,
                        ] + $this->outgoingOutputSalesOrderLineage($inspection, (int) $inspection->product_id));
                    $locked->forceFill(['rework_work_order_id' => $repair->id])->save();
                    if ($repair->id === $sourceWo->id) {
                        app(WorkOrderReworkService::class)->notifyProduction($sourceWo, $locked, 'required');
                    }
                }
            }

            if ($inspection?->work_order_output_id && $dispositionEnum === NcrDisposition::Scrap && ! $outgoingRecovery) {
                $workOrder = $inspection->workOrderOutput?->workOrder;
                if ($workOrder?->coversSalesOrderLine()) {
                    app(OutboxService::class)->record(new MrpReplanRequested([(int) $workOrder->sales_order_id], 'outgoing_scrap_disposition', $by?->id));
                }
            }
            if ($outgoingRecovery) {
                $inspection->forceFill(['accepted_quantity' => (int) $mrbQuantity + (int) $locked->previously_released_quantity])->save();
                if ($inspection->fresh()->canReleaseOutgoing()) {
                    app(OutboxService::class)->record(new OutgoingQuantityReleased($inspection));
                }
                $workOrder = $inspection->workOrderOutput?->workOrder;
                if ($workOrder?->coversSalesOrderLine()) {
                    app(OutboxService::class)->record(new MrpReplanRequested(
                        [(int) $workOrder->sales_order_id], 'outgoing_qc_recovery', $by->id,
                    ));
                }
            }

            if ($processRecovery && $inspection->startup_trial_required) {
                app(WorkOrderOutputService::class)->settleTrial($inspection, $by, (int) $mrbQuantity, $trialDefects);
            }
            if ($processRecovery && $inspection->work_order_output_id && $inspection->workOrderOutput?->production_started_at) {
                app(WorkOrderOutputService::class)->settleHistoricalHeld($inspection, $by, (int) $mrbQuantity, $trialDefects);
            }
            if ($grn !== null) {
                app(GrnService::class)->settleIncomingQc($grn, $by);
            }

            return $this->show($locked);
        });
    }

    /** True while this NCR's disposition would decide a pending incoming receipt. */
    public function awaitsMrbDecision(NonConformanceReport $ncr): bool
    {
        return $this->pendingIncomingGrnForMrb($ncr) !== null;
    }

    /**
     * The receipt an incoming NCR still decides: an incoming GRN inspection
     * whose GRN is pending_qc while MRB review is on. Null means the
     * disposition is a record only (legacy auto-rejected receipt, other stages).
     */
    private function pendingIncomingGrnForMrb(NonConformanceReport $ncr): ?GoodsReceiptNote
    {
        $inspection = $ncr->inspection;
        if (! $inspection || $inspection->stage !== InspectionStage::Incoming) {
            return null;
        }
        $entityType = $inspection->entity_type instanceof \BackedEnum
            ? $inspection->entity_type->value
            : (string) $inspection->entity_type;
        if ($entityType !== 'grn' || ! $inspection->entity_id) {
            return null;
        }
        if (! $this->settings->requiredBool('quality.incoming_failure.mrb_review', true)) {
            return null;
        }

        $grn = GoodsReceiptNote::query()->find((int) $inspection->entity_id);

        return $grn && $grn->status === GrnStatus::PendingQc ? $grn : null;
    }

    /** @return string|null the normalised sorted-good quantity */
    private function validateMrbDecision(
        NonConformanceReport $ncr,
        GoodsReceiptNote $grn,
        NcrDisposition $disposition,
        ?User $by,
        ?string $mrbAcceptedQuantity,
    ): ?string {
        if ($by === null) {
            throw new BusinessRuleException('An MRB decision on a pending receipt must record who made it.');
        }
        $inspection = $ncr->inspection;
        if ($disposition === NcrDisposition::Scrap) {
            throw new BusinessRuleException('Local scrap is not supported for an unaccepted receipt. Choose Return to supplier, or obtain an approved supplier disposal workflow.');
        }
        if ($disposition !== NcrDisposition::ReturnToSupplier) {
            throw new BusinessRuleException('Failed supplier receipts allow Return to supplier only.');
        }

        if ($mrbAcceptedQuantity === null || $mrbAcceptedQuantity === '') {
            return null;
        }
        if (! in_array($disposition, [NcrDisposition::ReturnToSupplier, NcrDisposition::Scrap], true)) {
            throw new BusinessRuleException('Good pieces kept after sorting apply only to return-to-supplier or scrap.');
        }
        if (! preg_match('/^\d+(\.\d{1,3})?$/', trim($mrbAcceptedQuantity))) {
            throw new BusinessRuleException('Good pieces kept must be a non-negative number with at most 3 decimals.');
        }
        $qty = bcadd(trim($mrbAcceptedQuantity), '0', 3);
        if (($inspection->sampling_basis === 'piece' || strcasecmp((string) ($inspection->source_uom_code ?: $inspection->grnItem?->item?->unit_of_measure), 'PCS') === 0)
            && bccomp($qty, bcdiv($qty, '1', 0), 3) !== 0) {
            throw new BusinessRuleException('Good pieces kept must be a whole-number quantity for PCS.');
        }

        if ($inspection->grn_item_id === null && $grn->items()->count() > 1) {
            throw new BusinessRuleException(
                'This inspection covers several receipt lines; sort per line or return the whole lot.'
            );
        }
        $line = $inspection->grn_item_id
            ? GrnItem::query()->find((int) $inspection->grn_item_id)
            : $grn->items()->first();
        $limit = (string) ($line?->quantity_received ?? $ncr->affected_quantity ?? '0');
        if (bccomp($qty, $limit, 3) >= 0) {
            throw new BusinessRuleException(
                "Good pieces kept must be fewer than the {$limit} received; if the whole lot is good, use use-as-is."
            );
        }

        if ($inspection->sampling_basis !== 'lot'
            && bccomp($qty, bcsub($limit, (string) max((int) $inspection->sample_defect_count, (int) $inspection->defect_count), 3), 3) > 0) {
            throw new BusinessRuleException('Sorting cannot recover pieces already recorded as defective; record completed rework instead.');
        }

        return $qty;
    }

    private function validateOutgoingRecovery(Inspection $inspection, NcrDisposition $disposition, string $goodQuantity): string
    {
        if (! in_array($disposition, [NcrDisposition::Scrap, NcrDisposition::Rework], true)) {
            throw new BusinessRuleException('Outgoing sorted-good recovery requires scrap or rework disposition.');
        }
        if (! preg_match('/^\d+(\.0{1,3})?$/', trim($goodQuantity))) {
            throw new BusinessRuleException('Outgoing good pieces kept must be a non-negative whole number.');
        }
        $quantity = bcadd(trim($goodQuantity), '0', 3);
        if (($inspection->counted_capture['workflow'] ?? null) === 'finish_first_v1') {
            $conforming = max(0, (int) $inspection->batch_quantity - max((int) $inspection->sample_defect_count, (int) $inspection->defect_count));
            if (bccomp($quantity, (string) $conforming, 3) > 0) {
                throw new BusinessRuleException("Repair defective pieces before releasing them. At most {$conforming} conforming pieces can release.");
            }
        }
        if (bccomp($quantity, (string) $inspection->batch_quantity, 3) > 0
            || ($disposition !== NcrDisposition::Rework
                && bccomp($quantity, (string) $inspection->batch_quantity, 3) === 0)) {
            throw new BusinessRuleException('Good pieces kept cannot exceed the batch; recovering the whole batch requires rework.');
        }
        if ($disposition !== NcrDisposition::Rework
            && bccomp($quantity, (string) ($inspection->batch_quantity - max((int) $inspection->sample_defect_count, (int) $inspection->defect_count)), 3) > 0) {
            throw new BusinessRuleException('Sorting cannot recover pieces already recorded as defective; record completed rework instead.');
        }
        $output = $inspection->workOrderOutput()->lockForUpdate()->first();
        if (! $output || $inspection->entity_type !== InspectionEntityType::WorkOrder
            || (int) $inspection->entity_id !== (int) $output->work_order_id
            || (int) $inspection->batch_quantity !== (int) $output->good_count
            || (int) $inspection->product_id !== (int) $output->workOrder?->product_id) {
            throw new BusinessRuleException('Outgoing recovery requires the original output batch and product lineage.');
        }
        $inspection->setRelation('workOrderOutput', $output);

        return $quantity;
    }

    /**
     * @param  array{inspected_quantity?: mixed, evidence_reference?: mixed, performed_by?: mixed, recovery_passed?: mixed}  $evidence
     * @return array<string, mixed>
     */
    private function validateSortingEvidence(Inspection $inspection, string $fullQuantity, ?User $by, array $evidence): array
    {
        if (($inspection->counted_capture['workflow'] ?? null) === 'finish_first_v1'
            && ($evidence['defects_segregated'] ?? null) !== true) {
            throw new BusinessRuleException('Confirm defective pieces were physically separated before release.');
        }
        if (! in_array($inspection->status, [InspectionStatus::Failed, InspectionStatus::Passed], true) || ($inspection->stage !== InspectionStage::InProcess && ! $inspection->isMakerChecked())) {
            throw new BusinessRuleException('Sorting release requires the original failed inspection to be checked.');
        }
        if ($by === null || ! User::query()->whereKey($by->id)->where('is_active', true)->exists()) {
            throw new BusinessRuleException('Sorting release must record an active approving user.');
        }
        $sorterId = $evidence['performed_by'] ?? null;
        if ((! is_int($sorterId) && ! is_string($sorterId)) || ! ctype_digit((string) $sorterId)
            || ! User::query()->whereKey((int) $sorterId)->where('is_active', true)->exists()) {
            throw new BusinessRuleException('Sorting evidence must identify the active user who performed the sorting and reinspection.');
        }
        $reviewedQcSeparation = $inspection->canConfirmReviewedSeparation($by);
        if (! $reviewedQcSeparation && ((int) $by->id === (int) $sorterId || (int) $by->id === (int) $inspection->inspector_id
            || $inspection->hasResultAuthorId((int) $by->id))) {
            throw new BusinessRuleException('Sorting release must be approved by someone other than the sorter and original inspection makers.');
        }
        $inspected = $evidence['inspected_quantity'] ?? null;
        if ((! is_string($inspected) && ! is_int($inspected))
            || ! preg_match('/^\d+(\.\d{1,3})?$/', trim((string) $inspected))
            || bccomp(trim((string) $inspected), $fullQuantity, 3) !== 0) {
            throw new BusinessRuleException('Sorting and reinspection must cover the full affected batch or receipt line.');
        }
        $reference = $evidence['evidence_reference'] ?? null;
        if (! is_string($reference) || trim($reference) === '' || mb_strlen(trim($reference)) > 500) {
            throw new BusinessRuleException('Provide a sorting and reinspection evidence reference in 1–500 characters.');
        }
        if (($evidence['recovery_passed'] ?? null) !== true) {
            throw new BusinessRuleException('Confirm that the sorted-good pieces passed reinspection before release.');
        }

        return [
            'sorting_inspected_quantity' => bcadd(trim((string) $inspected), '0', 3),
            'sorting_evidence_reference' => trim($reference),
            'sorting_performed_by' => (int) $sorterId,
            'sorting_approved_by' => $by->id,
            'sorting_approved_at' => now(),
            'sorting_uses_reviewed_qc' => $reviewedQcSeparation,
        ];
    }

    /**
     * Close the NCR. Triggers downstream effects based on disposition:
     *   - scrap on outgoing-QC inspection → auto-create replacement WO
     *   - return_to_supplier              → notify Purchasing role
     */
    public function close(NonConformanceReport $ncr, User $by): NonConformanceReport
    {
        if ($ncr->status->isTerminal()) {
            throw new BusinessRuleException('NCR is already closed.');
        }
        if (! $ncr->disposition) {
            throw new BusinessRuleException('Cannot close NCR without a disposition.');
        }

        return DB::transaction(function () use ($ncr, $by) {
            // Lock-then-guard: re-read so a concurrent close cannot double-
            // create the replacement/rework WO from a stale snapshot.
            $sourceInspection = $ncr->inspection_id ? Inspection::query()->find($ncr->inspection_id) : null;
            if ($sourceInspection?->startup_trial_required) {
                app(WorkOrderOutputService::class)->lockTrialSource($sourceInspection);
                Inspection::query()->whereKey($sourceInspection->id)->lockForUpdate()->firstOrFail();
            }
            $locked = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->getKey());
            if ($locked->status->isTerminal()) {
                throw new BusinessRuleException('NCR is already closed.');
            }
            // Full-piece WO dispositions use segregation, repair and checked QC as evidence.
            $fullPieceDisposition = $locked->inspection?->stage === InspectionStage::Outgoing
                && $locked->inspection?->work_order_output_id !== null
                && ($locked->inspection?->counted_capture['workflow'] ?? null) === 'finish_first_v1'
                && in_array($locked->disposition, [NcrDisposition::Rework, NcrDisposition::Scrap], true);
            if (! $fullPieceDisposition) {
                $counts = $locked->actions()->reorder()
                    ->selectRaw('action_type, COUNT(*) as c')->groupBy('action_type')->pluck('c', 'action_type');
                if (((int) ($counts[NcrActionType::Corrective->value] ?? 0)) < 1) {
                    throw new BusinessRuleException('Cannot close NCR without at least one Corrective action.');
                }
                if (((int) ($counts[NcrActionType::Preventive->value] ?? 0)) < 1) {
                    throw new BusinessRuleException('Cannot close NCR without at least one Preventive action.');
                }
            } elseif (! $locked->sorting_approved_at || $locked->mrb_decided_at === null) {
                throw new BusinessRuleException('Approve physical separation and release quantities before closing this NCR.');
            }

            $lateIncoming = (bool) $locked->is_late_incoming;
            if ($lateIncoming) {
                $reviews = MaterialReviewRecord::query()->where('ncr_id', $locked->id)->get();
                if (bccomp($reviews->reduce(fn ($sum, $review) => bcadd($sum, (string) $review->quantity, 3), '0.000'), (string) ($locked->late_hold_quantity ?? '0'), 3) < 0
                    || $reviews->contains(fn ($review) => $review->status->value === 'held')) {
                    throw new BusinessRuleException('Resolve the late incoming quarantine holds before closing this NCR.');
                }
            }
            $inlineRepair = false;
            if ($locked->disposition === NcrDisposition::Rework && $locked->inspection?->stage === InspectionStage::Outgoing) {
                $repair = $locked->reworkWorkOrder;
                $inlineRepair = $repair && (int) $repair->id === (int) $locked->inspection->entity_id;
                if ($locked->confirmed_rework_quantity === null) {
                    throw new BusinessRuleException('Confirm sorting and create the repair work order before closing this NCR.');
                }
                if ((int) $locked->confirmed_rework_quantity > 0 && ! $repair) {
                    throw new BusinessRuleException('This NCR requires a linked repair work order before closure.');
                }
                if ($inlineRepair && ! $locked->rework_finished_at) {
                    throw new BusinessRuleException('Finish the inline repair before closing this NCR.');
                }
                if ($repair && ! $inlineRepair && (! in_array($repair->status->value, ['completed', 'closed'], true)
                    || (int) $repair->quantity_produced !== (int) $repair->quantity_target
                    || ((int) $repair->quantity_good > 0 && ! $repair->outputs()->where('good_count', '>', 0)->exists()))) {
                    throw new BusinessRuleException('The linked repair work order must finish and pass outgoing QC before the NCR closes.');
                }
                if ($repair) {
                    foreach ($repair->outputs()->when($inlineRepair, fn ($q) => $q->where('rework_ncr_id', $locked->id))
                        ->where('good_count', '>', 0)->get() as $repairOutput) {
                        $qc = $repairOutput->inspections()->where('stage', 'outgoing')->where('status', '!=', 'cancelled')->orderByDesc('id')->first();
                        if (! $qc || ((! $qc->canReleaseOutgoing() || (int) $qc->accepted_quantity !== (int) $repairOutput->good_count) && ! $qc->hasResolvedOutgoingDisposition())) {
                            throw new BusinessRuleException('The linked repair must have independently checked, released output before this NCR closes.');
                        }
                    }
                }
            }

            $locked->forceFill([
                'status' => NcrStatus::Closed->value,
                'closed_by' => $by->id,
                'closed_at' => now(),
            ])->save();

            $outgoingInspection = $locked->inspection_id ? Inspection::find($locked->inspection_id) : null;
            $dispositionQuantity = $locked->sorting_approved_at !== null
                && $locked->mrb_accepted_quantity !== null
                && $outgoingInspection?->stage === InspectionStage::Outgoing
                ? max(0, (int) ($locked->recovery_batch_quantity ?? $outgoingInspection->batch_quantity) - (int) $locked->mrb_accepted_quantity)
                : (int) ($locked->recovery_batch_quantity ?? $locked->affected_quantity);

            if ($locked->disposition === NcrDisposition::Scrap && $outgoingInspection?->work_order_output_id && $dispositionQuantity > 0) {
                $output = $outgoingInspection->workOrderOutput;
                $receipt = $output?->productionReceiptMovement;
                if (! $receipt || ! $receipt->lot_number || ! $receipt->to_location_id) {
                    throw new BusinessRuleException('Scrap requires the original traceable finished-goods receipt. Restore the receipt before closing this NCR.');
                }
                app(StockMovementService::class)->move(new StockMovementInput(
                    type: StockMovementType::Scrap,
                    itemId: (int) $receipt->item_id,
                    quantity: (string) $dispositionQuantity,
                    fromLocationId: app(QualityStockHoldService::class)->dispositionLocation((int) $receipt->item_id, (string) $receipt->lot_number, (string) $dispositionQuantity),
                    referenceType: 'work_order_output',
                    referenceId: (int) $output->id,
                    createdBy: (int) $by->id,
                    lotNumber: (string) $receipt->lot_number,
                    remarks: "Scrap confirmed by NCR {$locked->ncr_number}.",
                ));
            }

            // Replacement WO: outgoing-QC scrap → re-create the lost output.
            if ($locked->disposition === NcrDisposition::Scrap
                && $locked->inspection_id
                && $locked->product_id
                && $dispositionQuantity > 0) {
                $insp = Inspection::find($locked->inspection_id);
                if ($insp && $insp->stage === InspectionStage::Outgoing && ! $this->mrpReplansOutput($insp)) {
                    $wo = $this->createRequiredWorkOrder([
                        'product_id' => $locked->product_id,
                        'quantity_target' => $dispositionQuantity,
                        'planned_start' => now()->addDay()->toDateString(),
                        'planned_end' => now()->addDays($this->settings->requiredInt('quality.ncr.replacement_work_order_lead_days', 1))->toDateString(),
                        'priority' => $this->settings->requiredInt('quality.ncr.replacement_work_order_priority', 0, 10),
                        'parent_ncr_id' => $locked->id,
                        'created_by' => $by->id,
                    ] + $this->outgoingOutputSalesOrderLineage($insp, (int) $locked->product_id));
                    $locked->forceFill(['replacement_work_order_id' => $wo->id])->save();
                }
            }

            // Return to supplier → open the shared supplier-return RMA so the
            // receipt reversal, supplier credit note and optional replacement
            // PO are handled by the ONE engine, then still notify Purchasing.
            // Without GRN/PO lineage there is no safe stock or credit action, so
            // the close must roll back and remain operator-actionable.
            if ($locked->disposition === NcrDisposition::ReturnToSupplier && ! $lateIncoming) {
                $rma = $this->openSupplierReturnRmaForNcr($locked, $by);
                $this->notifyPurchasing($locked, $rma);
            }

            if ($locked->rework_work_order_id) {
                $repairOrder = $locked->reworkWorkOrder;
                $repairRejects = $inlineRepair ? (int) $repairOrder->outputs()->where('rework_ncr_id', $locked->id)->sum('reject_count') : (int) ($repairOrder?->quantity_rejected ?? 0);
                if ($repairOrder?->coversSalesOrderLine() && $repairRejects > 0) {
                    app(OutboxService::class)->record(new MrpReplanRequested([(int) $repairOrder->sales_order_id], 'repair_rejected_output', $by->id));
                } elseif ($repairOrder && $repairRejects > 0 && ! $locked->replacement_work_order_id) {
                    $replacement = $this->createRequiredWorkOrder([
                        'product_id' => $locked->product_id, 'quantity_target' => $repairRejects,
                        'planned_start' => now()->addDay()->toDateString(),
                        'planned_end' => now()->addDays($this->settings->requiredInt('quality.ncr.replacement_work_order_lead_days', 1))->toDateString(),
                        'priority' => $this->settings->requiredInt('quality.ncr.replacement_work_order_priority', 0, 10),
                        'parent_ncr_id' => $locked->id, 'created_by' => $by->id,
                    ]);
                    $locked->forceFill(['replacement_work_order_id' => $replacement->id])->save();
                }
            }

            // CAPA effectiveness loop: schedule 30-day verification checks for
            // the corrective + preventive actions now that the NCR is closed.
            if ($fullPieceDisposition && ! $locked->actions()->whereIn('action_type', ['corrective', 'preventive'])->exists()) {
                $locked->forceFill([
                    'effectiveness_status' => EffectivenessStatus::NotApplicable->value,
                    'effectiveness_closed_at' => now(),
                ])->save();
            } else {
                app(EffectivenessService::class)->scheduleVerification($locked);
            }
            if ($locked->inspection?->entity_type?->value === 'work_order') {
                $wo = WorkOrder::query()->find($locked->inspection->entity_id);
                if ($wo) {
                    app(WorkOrderService::class)->requestQualityClose($wo, $by);
                }
            }

            return $this->show($locked);
        });
    }

    public function cancel(NonConformanceReport $ncr, ?string $reason, User $by): NonConformanceReport
    {
        if ($ncr->status->isTerminal()) {
            throw new BusinessRuleException('NCR is already closed.');
        }

        return DB::transaction(function () use ($ncr, $reason, $by) {
            // Lock-then-guard: a stale cancel must not flip a concurrently
            // closed NCR to cancelled.
            $sourceInspection = $ncr->inspection_id ? Inspection::query()->find($ncr->inspection_id) : null;
            if ($sourceInspection?->startup_trial_required) {
                app(WorkOrderOutputService::class)->lockTrialSource($sourceInspection);
                Inspection::query()->whereKey($sourceInspection->id)->lockForUpdate()->firstOrFail();
            }
            $locked = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->getKey());
            if ($locked->status->isTerminal()) {
                throw new BusinessRuleException('NCR is already closed.');
            }
            if ($locked->is_late_incoming) {
                throw new BusinessRuleException('A late incoming incident owns suspect stock. Resolve its holds and close the NCR instead of cancelling it.');
            }
            if ($locked->mrb_decided_at !== null) {
                throw new BusinessRuleException('An NCR with a settled material decision cannot be cancelled.');
            }
            $inspection = $locked->inspection_id ? Inspection::query()->lockForUpdate()->find($locked->inspection_id) : null;
            if ($inspection && $locked->original_accepted_quantity !== null) {
                $inspection->forceFill(['accepted_quantity' => $locked->original_accepted_quantity])->save();
            }
            $locked->forceFill([
                'status' => NcrStatus::Cancelled->value,
                'closed_by' => $by->id,
                'closed_at' => now(),
                'corrective_action' => trim(($locked->corrective_action ? $locked->corrective_action."\n" : '').'[cancelled] '.($reason ?: 'no reason given')),
            ])->save();

            if ($inspection?->entity_type === InspectionEntityType::WorkOrder) {
                $wo = WorkOrder::find($inspection->entity_id);
                if ($wo) {
                    app(WorkOrderService::class)->requestQualityClose($wo, $by);
                }
            }

            return $this->show($locked);
        });
    }

    /** Only the inspected output batch can supply a replacement's SO lineage. */
    private function outgoingOutputSalesOrderLineage(Inspection $inspection, int $productId): array
    {
        $source = $inspection->workOrderOutput?->workOrder;
        if (! $source?->sales_order_id
            || $inspection->entity_type !== InspectionEntityType::WorkOrder
            || (int) $inspection->entity_id !== (int) $source->id
            || (int) $source->product_id !== $productId) {
            return [];
        }

        return [
            'sales_order_id' => $source->sales_order_id,
            'sales_order_item_id' => $source->sales_order_item_id,
        ];
    }

    /**
     * A failed batch from an order line is MRP's to replace: MRP subtracts
     * failed outgoing output from the line and re-plans the shortfall as soon
     * as the result is final (QueueMrpOnOutgoingInspectionFailed). A second WO
     * from NCR close, often days later after the CAPA, doubled the production.
     */
    private function mrpReplansOutput(Inspection $inspection): bool
    {
        return (bool) $inspection->workOrderOutput?->workOrder?->coversSalesOrderLine();
    }

    /** Lazy resolve to keep the Quality module bootable without Production. */
    private function workOrderService(): ?WorkOrderService
    {
        try {
            return app(WorkOrderService::class);
        } catch (\Throwable $exception) {
            Log::warning('Production work-order service could not be resolved for NCR disposition.', [
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function createRequiredWorkOrder(array $data): WorkOrder
    {
        $service = $this->workOrderService();
        if ($service === null) {
            throw new BusinessRuleException(
                'This NCR disposition requires the Production work-order service, which is currently unavailable.'
            );
        }

        try {
            return $service->createDraft($data);
        } catch (\Throwable $exception) {
            Log::error('NCR close could not create the required production work order.', [
                'parent_ncr_id' => $data['parent_ncr_id'] ?? null,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new BusinessRuleException(
                'The required production work order could not be created; the NCR remains open.',
                0,
                $exception,
            );
        }
    }

    /**
     * Unify the NCR return-to-supplier disposition with the supplier-return
     * engine. When the NCR's inspection carries GRN/PO lineage, open (or reuse)
     * the same RMA the incoming-QC rejection path uses; when it does not, there
     * is no receipt to reverse or credit against, so keep the notify-only
     * behaviour and record the reason instead of opening an unusable RMA.
     *
     * The dedupe key is deliberately shared with `GrnService`'s rejection path
     * (`grn-rejection:<id>`): if the queue already rejected the GRN and opened
     * the RMA, this returns it rather than crediting the same goods twice.
     */
    private function openSupplierReturnRmaForNcr(NonConformanceReport $ncr, User $by): ReturnRequest
    {
        $inspection = $ncr->inspection_id ? Inspection::find($ncr->inspection_id) : null;
        if (! $inspection) {
            throw new BusinessRuleException(
                'Cannot close return_to_supplier NCR without a linked inspection and receipt lineage.',
            );
        }

        $grnItem = $this->resolveGrnItemForReturn($inspection);
        $grn = $grnItem?->grn;
        if (! $grnItem || ! $grn || ! $grn->vendor_id) {
            throw new BusinessRuleException(
                'Cannot close return_to_supplier NCR without complete GRN, vendor, and purchase-lineage data.',
            );
        }

        $quantity = $this->ncrReturnQuantity($ncr, $grnItem);
        if (bccomp($quantity, '0', 3) <= 0) {
            throw new BusinessRuleException(
                'Cannot close return_to_supplier NCR because the resolved return quantity is zero.',
            );
        }

        $poItem = $grnItem->purchaseOrderItem;

        try {
            return app(ReturnRequestService::class)
                ->openSupplierReturnForReversedGoods(
                    vendorId: (int) $grn->vendor_id,
                    purchaseOrderId: $grn->purchase_order_id ? (int) $grn->purchase_order_id : null,
                    goodsReceiptNoteId: (int) $grn->id,
                    lines: [[
                        'grn_item_id' => (int) $grnItem->id,
                        'purchase_order_item_id' => $grnItem->purchase_order_item_id
                            ? (int) $grnItem->purchase_order_item_id
                            : null,
                        'item_id' => (int) $grnItem->item_id,
                        'quantity' => $quantity,
                        'unit_price' => (string) ($poItem?->unit_price ?? $grnItem->unit_cost),
                        'reason' => "NCR {$ncr->ncr_number}: {$ncr->defect_description}",
                        'lot_number' => $grnItem->material_lot_number,
                        // A failed incoming inspection's GRN is rejected by
                        // RejectGRNOnQcFail, which already reversed the PO
                        // receipt; never reverse it a second time.
                        'reversal_already_applied' => true,
                    ]],
                    by: $by,
                    reason: "NCR {$ncr->ncr_number} closed with return_to_supplier.",
                    dedupeKey: 'grn-rejection:'.$grn->id,
                );
        } catch (\Throwable $e) {
            Log::error('NcrService: failed to open a supplier-return RMA for return_to_supplier NCR.', [
                'ncr_id' => $ncr->id,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw new BusinessRuleException(
                'The supplier-return RMA could not be created; the NCR remains open.',
                0,
                $e,
            );
        }
    }

    /** The GRN line an inspection line belongs to, when one exists. */
    private function resolveGrnItemForReturn(Inspection $inspection): ?GrnItem
    {
        if ($inspection->grn_item_id) {
            return GrnItem::query()
                ->with(['grn', 'purchaseOrderItem'])
                ->find((int) $inspection->grn_item_id);
        }

        $entityType = $inspection->entity_type instanceof \BackedEnum
            ? $inspection->entity_type->value
            : (string) $inspection->entity_type;

        if ($entityType === 'grn' && $inspection->entity_id && $inspection->item_id) {
            return GrnItem::query()
                ->with(['grn', 'purchaseOrderItem'])
                ->where('goods_receipt_note_id', (int) $inspection->entity_id)
                ->where('item_id', (int) $inspection->item_id)
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    /** NCR scope, never more than the receipt actually carries. */
    private function ncrReturnQuantity(NonConformanceReport $ncr, GrnItem $grnItem): string
    {
        $received = (string) $grnItem->quantity_received;
        $affected = (string) ($ncr->affected_quantity ?? '0');

        if (bccomp($affected, '0', 3) > 0 && bccomp($affected, $received, 3) < 0) {
            return $affected;
        }

        return $received;
    }

    private function notifyPurchasing(NonConformanceReport $ncr, ReturnRequest $rma): void
    {
        $roles = array_values(array_filter((array) $this->settings->get('quality.ncr.return_to_supplier.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
        if ($roles === []) {
            return;
        }
        $recipients = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission('return_management.view'));
        if ($recipients->isEmpty()) {
            return;
        }

        $severity = $ncr->severity instanceof \BackedEnum
            ? $ncr->severity->value
            : (string) $ncr->severity;
        $this->notifications->sendForSubject($rma, $recipients, 'ncr.return_to_supplier', [
            'title' => "Return-to-supplier required: NCR {$ncr->ncr_number}",
            'message' => "NCR {$ncr->ncr_number} closed with a supplier return. Review RMA {$rma->rma_number}.",
            'link_to' => "/return-management/{$rma->hash_id}",
            'entity_type' => 'ncr',
            'entity_id' => $ncr->hash_id,
            'ncr_number' => $ncr->ncr_number,
            'severity' => $severity,
        ]);
    }

    private function isInspectionUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'ncr_inspection_unique')
            || str_contains($message, 'non_conformance_reports.inspection_id');
    }
}
