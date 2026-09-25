<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\SearchOperator;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Jobs\ProcessNcrRecurrenceScan;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NcrRecurrenceScan;
use App\Modules\Quality\Models\NonConformanceReport;
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
            'inspection:id,inspection_number,stage,status,entity_type,entity_id',
            'creator:id,name,role_id',
            'assignee:id,name',
            'reworkWorkOrder:id,wo_number,status,quantity_target',
        ]);

        foreach (['source', 'severity', 'status', 'disposition'] as $f) {
            if (! empty($filters[$f])) $q->where($f, $filters[$f]);
        }
        if (! empty($filters['product_id']))    $q->where('product_id', $filters['product_id']);
        if (! empty($filters['inspection_id'])) $q->where('inspection_id', $filters['inspection_id']);
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
            'inspection:id,inspection_number,stage,status,product_id,entity_type,entity_id',
            'creator:id,name,role_id',
            'assignee:id,name',
            'closer:id,name',
            'mrbDecider:id,name',
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
            $inspection = ! empty($data['inspection_id'])
                ? Inspection::query()->with('measurements')->find((int) $data['inspection_id'])
                : null;
            $defectSignature = (string) ($data['defect_signature'] ?? '');
            if ($defectSignature === '') {
                $defectSignature = $inspection
                    ? NcrRecurrenceDetector::signatureForInspection($inspection)
                    : NcrRecurrenceDetector::descriptionSignature((string) $data['defect_description']);
            }

            $ncr = NonConformanceReport::create([
                'ncr_number'        => $this->sequences->generate('ncr'),
                'source'            => NcrSource::from((string) $data['source'])->value,
                'severity'          => NcrSeverity::from((string) $data['severity'])->value,
                'status'            => NcrStatus::Open->value,
                'product_id'        => $data['product_id'] ?? null,
                'inspection_id'     => $data['inspection_id'] ?? null,
                'complaint_id'      => $data['complaint_id'] ?? null,
                'defect_description' => $data['defect_description'],
                'defect_signature'  => $defectSignature,
                'affected_quantity' => (int) ($data['affected_quantity'] ?? 0),
                'created_by'        => $by->id,
                'assigned_to'       => $data['assigned_to'] ?? null,
                'is_auto_generated' => (bool) ($data['is_auto_generated'] ?? false),
            ]);

            NcrRecurrenceScan::create([
                'ncr_id'       => $ncr->id,
                'status'       => 'pending',
                'available_at' => now(),
            ]);

            DB::afterCommit(fn () => ProcessNcrRecurrenceScan::dispatch($ncr->id));

            return $ncr->fresh();
        });

        return $ncr;
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
        if ($existing) return $this->show($existing);

        $criticalFail = $inspection->measurements?->contains(
            fn ($m) => $m->is_critical && $m->is_pass === false
        ) ?? false;
        $severity = $criticalFail
            ? NcrSeverity::Critical->value
            : ($inspection->defect_count > $inspection->accept_count
                ? NcrSeverity::High->value
                : NcrSeverity::Medium->value);

        // Build description: for lot_checklist, mention the defect count found in sample.
        $descriptionBase = 'Automated NCR from inspection '.$inspection->inspection_number.': ';
        if ($inspection->inspection_mode && $inspection->inspection_mode->value === 'lot_checklist' && $inspection->sample_defect_count !== null) {
            $descriptionBase .= $inspection->sample_defect_count.' defective piece(s) found in a sample of '
                              .$inspection->sample_size.' (accept ≤ '.$inspection->accept_count.')';
        } else {
            $descriptionBase .= $inspection->defect_count.' defect(s)';
        }
        $descriptionBase .= ' on '.$inspection->stage->value.' stage'
                          .($criticalFail ? ' (critical parameter failure)' : '').'.';

        try {
            $ncr = $this->create([
                'source'             => NcrSource::InspectionFail->value,
                'severity'           => $severity,
                'product_id'         => $inspection->product_id,
                'inspection_id'      => $inspection->id,
                'defect_description' => $descriptionBase,
                'affected_quantity'  => $inspection->batch_quantity,
                'is_auto_generated'  => true,
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
            $qcHeads = \App\Modules\Auth\Models\User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $notificationRoles))
                ->where('is_active', true)
                ->get();
            app(NotificationService::class)->send($qcHeads, 'auto_ncr_created', [
                'title'         => 'NCR auto-created',
                'message'       => "NCR auto-created from {$inspection->stage->value} inspection. {$inspection->defect_count} pcs rejected. {$severity} severity. Review disposition.",
                'link_to'       => "/quality/ncrs/{$ncr->hash_id}",
                'entity_type'   => 'ncr',
                'entity_id'     => $ncr->hash_id,
                'ncr_number'    => $ncr->ncr_number,
                'inspection_id' => $inspection->hash_id,
                'severity'      => $severity,
            ]);
        } catch (\Throwable $e) {
            // NCR is already persisted; only the QC notification fan-out failed.
            \Illuminate\Support\Facades\Log::warning(
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
            $locked = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->getKey());
            if ($locked->status->isTerminal()) {
                throw new BusinessRuleException('Cannot add actions to a closed NCR.');
            }
            $action = NcrAction::create([
                'ncr_id'       => $locked->id,
                'action_type'  => NcrActionType::from((string) $data['action_type'])->value,
                'description'  => $data['description'],
                'performed_by' => $by->id,
                'performed_at' => $data['performed_at'] ?? now(),
                // CAPA: seed ownership + due date so the effectiveness loop has
                // an accountable owner once the NCR closes.
                'owner_id'     => $data['owner_id'] ?? $by->id,
                'due_date'     => $data['due_date'] ?? now()->addDays($this->settings->requiredInt('quality.effectiveness.check_interval_days', 1))->toDateString(),
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
     * grant it. $mrbAcceptedQuantity (return_to_supplier/scrap only) is the
     * number of good pieces kept after sorting; the rest goes back.
     */
    public function setDisposition(
        NonConformanceReport $ncr,
        string $disposition,
        ?string $rootCause,
        ?string $correctiveAction,
        ?User $by = null,
        ?string $mrbAcceptedQuantity = null,
    ): NonConformanceReport {
        if ($ncr->status->isTerminal()) {
            throw new BusinessRuleException('NCR is already closed.');
        }
        $dispositionEnum = NcrDisposition::from($disposition);

        return DB::transaction(function () use ($ncr, $dispositionEnum, $rootCause, $correctiveAction, $by, $mrbAcceptedQuantity) {
            // Lock-then-guard: a stale disposition must not flip a concurrently closed NCR back to in_progress.
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
                && ($currentDisposition !== $dispositionEnum->value || $mrbAcceptedQuantity !== null)) {
                throw new BusinessRuleException(
                    'The Material Review Board decision is final: the receipt was settled against it.'
                );
            }

            $grn = $this->pendingIncomingGrnForMrb($locked);
            $mrbQuantity = $grn !== null
                ? $this->validateMrbDecision($locked, $grn, $dispositionEnum, $by, $mrbAcceptedQuantity)
                : null;
            if ($grn === null && $mrbAcceptedQuantity !== null) {
                throw new BusinessRuleException(
                    'Good pieces kept after sorting can only be recorded while the receipt is waiting for the MRB decision.'
                );
            }

            $fill = [
                'disposition'       => $dispositionEnum->value,
                'root_cause'        => $rootCause ?: $locked->root_cause,
                'corrective_action' => $correctiveAction ?: $locked->corrective_action,
                'status'            => NcrStatus::InProgress->value,
            ];
            if ($grn !== null) {
                $fill['mrb_accepted_quantity'] = $mrbQuantity;
                $fill['mrb_decided_by'] = $by->id;
                $fill['mrb_decided_at'] = now();
            }
            $locked->forceFill($fill)->save();

            if ($grn !== null) {
                app(\App\Modules\Inventory\Services\GrnService::class)->settleIncomingQc($grn, $by);
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
    private function pendingIncomingGrnForMrb(NonConformanceReport $ncr): ?\App\Modules\Inventory\Models\GoodsReceiptNote
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

        $grn = \App\Modules\Inventory\Models\GoodsReceiptNote::query()->find((int) $inspection->entity_id);

        return $grn && $grn->status === \App\Modules\Inventory\Enums\GrnStatus::PendingQc ? $grn : null;
    }

    /** @return string|null the normalised sorted-good quantity */
    private function validateMrbDecision(
        NonConformanceReport $ncr,
        \App\Modules\Inventory\Models\GoodsReceiptNote $grn,
        NcrDisposition $disposition,
        ?User $by,
        ?string $mrbAcceptedQuantity,
    ): ?string {
        if ($by === null) {
            throw new BusinessRuleException('An MRB decision on a pending receipt must record who made it.');
        }
        $inspection = $ncr->inspection;

        // A concession overrides a failed verdict; the inspector who failed
        // the lot cannot also be the one who waives it.
        if ($disposition === NcrDisposition::UseAsIs
            && $inspection->inspector_id
            && (int) $by->id === (int) $inspection->inspector_id) {
            throw new BusinessRuleException(
                'Use-as-is is a concession: it must be granted by someone other than the inspector who failed the lot.'
            );
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

        return bccomp($qty, '0', 3) === 0 ? null : $qty;
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

        $counts = $ncr->actions()
            ->reorder()
            ->selectRaw('action_type, COUNT(*) as c')
            ->groupBy('action_type')
            ->pluck('c', 'action_type');

        if (((int) ($counts[NcrActionType::Corrective->value] ?? 0)) < 1) {
            throw new BusinessRuleException('Cannot close NCR without at least one Corrective action.');
        }
        if (((int) ($counts[NcrActionType::Preventive->value] ?? 0)) < 1) {
            throw new BusinessRuleException('Cannot close NCR without at least one Preventive action.');
        }

        return DB::transaction(function () use ($ncr, $by) {
            // Lock-then-guard: re-read so a concurrent close cannot double-
            // create the replacement/rework WO from a stale snapshot.
            $locked = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->getKey());
            if ($locked->status->isTerminal()) {
                throw new BusinessRuleException('NCR is already closed.');
            }

            $locked->forceFill([
                'status'    => NcrStatus::Closed->value,
                'closed_by' => $by->id,
                'closed_at' => now(),
            ])->save();

            // Replacement WO: outgoing-QC scrap → re-create the lost output.
            if ($locked->disposition === NcrDisposition::Scrap
                && $locked->inspection_id
                && $locked->product_id
                && $locked->affected_quantity > 0) {
                $insp = Inspection::find($locked->inspection_id);
                if ($insp && $insp->stage === InspectionStage::Outgoing && ! $this->mrpReplansOutput($insp)) {
                    $wo = $this->createRequiredWorkOrder([
                        'product_id'      => $locked->product_id,
                        'quantity_target' => $locked->affected_quantity,
                        'planned_start'   => now()->addDay()->toDateString(),
                        'planned_end'     => now()->addDays($this->settings->requiredInt('quality.ncr.replacement_work_order_lead_days', 1))->toDateString(),
                        'priority'        => $this->settings->requiredInt('quality.ncr.replacement_work_order_priority', 0, 10),
                        'parent_ncr_id'   => $locked->id,
                        'created_by'      => $by->id,
                    ]);
                    $locked->forceFill(['replacement_work_order_id' => $wo->id])->save();
                }
            }

            // T3.1.B — Rework disposition mirrors Scrap branch but creates a
            // rework WO on the configured replacement timeline and priority.
            if ($locked->disposition === NcrDisposition::Rework
                && $locked->inspection_id
                && $locked->product_id
                && $locked->affected_quantity > 0) {
                $insp = Inspection::find($locked->inspection_id);
                if ($insp && $insp->stage === InspectionStage::Outgoing && ! $this->mrpReplansOutput($insp)) {
                    $wo = $this->createRequiredWorkOrder([
                        'product_id'      => $locked->product_id,
                        'quantity_target' => (int) $locked->affected_quantity,
                        'planned_start'   => now()->addDay()->toDateString(),
                        'planned_end'     => now()->addDays($this->settings->requiredInt('quality.ncr.replacement_work_order_lead_days', 1))->toDateString(),
                        'priority'        => $this->settings->requiredInt('quality.ncr.replacement_work_order_priority', 0, 10),
                        'parent_ncr_id'   => $locked->id,
                        'created_by'      => $by->id,
                    ]);
                    $locked->forceFill(['rework_work_order_id' => $wo->id])->save();
                }
            }

            // Return to supplier → open the shared supplier-return RMA so the
            // receipt reversal, supplier credit note and optional replacement
            // PO are handled by the ONE engine, then still notify Purchasing.
            // Without GRN/PO lineage there is no safe stock or credit action, so
            // the close must roll back and remain operator-actionable.
            if ($locked->disposition === NcrDisposition::ReturnToSupplier) {
                $this->openSupplierReturnRmaForNcr($locked, $by);
                $this->notifyPurchasing($locked);
            }

            // CAPA effectiveness loop: schedule 30-day verification checks for
            // the corrective + preventive actions now that the NCR is closed.
            app(EffectivenessService::class)->scheduleVerification($locked);

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
            $locked = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->getKey());
            if ($locked->status->isTerminal()) {
                throw new BusinessRuleException('NCR is already closed.');
            }
            $locked->forceFill([
                'status'           => NcrStatus::Cancelled->value,
                'closed_by'        => $by->id,
                'closed_at'        => now(),
                'corrective_action'=> trim(($locked->corrective_action ? $locked->corrective_action."\n" : '').'[cancelled] '.($reason ?: 'no reason given')),
            ])->save();
            return $this->show($locked);
        });
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
    private function workOrderService(): ?\App\Modules\Production\Services\WorkOrderService
    {
        try {
            return app(\App\Modules\Production\Services\WorkOrderService::class);
        } catch (\Throwable $exception) {
            Log::warning('Production work-order service could not be resolved for NCR disposition.', [
                'exception' => $exception,
            ]);
            return null;
        }
    }

    private function createRequiredWorkOrder(array $data): \App\Modules\Production\Models\WorkOrder
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
                'exception'     => $exception::class,
                'message'       => $exception->getMessage(),
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
    private function openSupplierReturnRmaForNcr(NonConformanceReport $ncr, User $by): void
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
            app(\App\Modules\ReturnManagement\Services\ReturnRequestService::class)
                ->openSupplierReturnForReversedGoods(
                    vendorId: (int) $grn->vendor_id,
                    purchaseOrderId: $grn->purchase_order_id ? (int) $grn->purchase_order_id : null,
                    goodsReceiptNoteId: (int) $grn->id,
                    lines: [[
                        'grn_item_id'              => (int) $grnItem->id,
                        'purchase_order_item_id'   => $grnItem->purchase_order_item_id
                            ? (int) $grnItem->purchase_order_item_id
                            : null,
                        'item_id'                  => (int) $grnItem->item_id,
                        'quantity'                 => $quantity,
                        'unit_price'               => (string) ($poItem?->unit_price ?? $grnItem->unit_cost),
                        'reason'                   => "NCR {$ncr->ncr_number}: {$ncr->defect_description}",
                        'lot_number'               => $grnItem->material_lot_number,
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
                'ncr_id'    => $ncr->id,
                'error'     => $e->getMessage(),
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

    private function notifyPurchasing(NonConformanceReport $ncr): void
    {
        $roles = array_values(array_filter((array) $this->settings->get('quality.ncr.return_to_supplier.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
        if ($roles === []) return;
        $recipients = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
            ->where('is_active', true)
            ->get();
        if ($recipients->isEmpty()) return;

        $severity = $ncr->severity instanceof \BackedEnum
            ? $ncr->severity->value
            : (string) $ncr->severity;
        $this->notifications->send($recipients, 'ncr.return_to_supplier', [
            'title'         => "Return-to-supplier required: NCR {$ncr->ncr_number}",
            'message'       => "NCR {$ncr->ncr_number} closed with disposition return_to_supplier. Quantity: {$ncr->affected_quantity}.",
            'link_to'       => "/quality/ncrs/{$ncr->hash_id}",
            'entity_type'   => 'ncr',
            'entity_id'     => $ncr->hash_id,
            'ncr_number'    => $ncr->ncr_number,
            'affected_qty'  => (int) $ncr->affected_quantity,
            'severity'      => $severity,
        ]);
    }

    private function isInspectionUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'ncr_inspection_unique')
            || str_contains($message, 'non_conformance_reports.inspection_id');
    }
}
