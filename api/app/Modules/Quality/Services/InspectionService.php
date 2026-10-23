<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Models\LateProductionReport;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Enums\CalibrationStatus;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Enums\InspectionOutcome;
use App\Modules\Quality\Enums\InspectionParameterType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Models\CalibrationRecord;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionDefectivePiece;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionPieceReading;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Support\ChecklistDefinition;
use App\Modules\Quality\Support\InspectionStateMachine;
use App\Modules\Quality\Support\LotDefectCounter;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 7 — Task 60. Lifecycle service for quality inspections.
 *
 * create()                — opens a draft inspection with measurement scaffold
 * recordMeasurements()    — patches measured values, auto-evaluates pass/fail
 * complete()              — finalises status (passed | failed) using AQL plan
 * cancel()                — voids a non-terminal inspection
 *
 * The auto-evaluation rule (in priority order):
 *   1. Any measurement on a critical parameter that fails → Failed.
 *   2. defect_count > accept_count from the AQL plan       → Failed.
 *   3. Otherwise, all sampled units evaluated and no critical fail → Passed.
 */
class InspectionService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly InspectionStateMachine $states,
        private readonly SettingsService $settings,
    ) {}

    private function listQuery(array $filters): Builder
    {
        $q = Inspection::query()
            ->with([
                'product:id,part_number,name',
                'item:id,code,name,unit_of_measure',
                'inspector:id,name,role_id',
                'reviewer:id,name,role_id',
                'resultAuthors:id,inspection_id,user_id',
                'resultAuthors.user:id,name',
                'spec:id,product_id,version',
                'specRevision:id,inspection_spec_id,version,created_by,notes,capture_method',
                'qualityPlan:id,item_id,vendor_id,version,sampling_method,capture_method',
                'workOrderOutput.workOrder:id,wo_number,product_id',
                'trialOutput',
                'ncr',
            ]);

        if (! empty($filters['stage'])) {
            $q->where('stage', $filters['stage']);
        }
        if (($filters['work_queue'] ?? null) === 'to_inspect') {
            $q->whereIn('status', ['draft', 'in_progress']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $q->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('created_at', '<=', $filters['to']);
        }
        if (! empty($filters['product_id'])) {
            // InspectionController::index() forwards the raw query bag, so the
            // SPA's hash string would hit a bigint column (Postgres 22P02 → 500).
            $q->where('product_id', HashIdFilter::decode($filters['product_id'], Product::class) ?? 0);
        }
        if (! empty($filters['entity_type']) && ! empty($filters['entity_id'])) {
            // entity_id is polymorphic, so the model to decode against is only
            // known from entity_type. An unknown type falls through to 0 rather
            // than casting the hash to 0 silently.
            $entityModel = $this->entityModelClass((string) $filters['entity_type']);
            $q->where('entity_type', $filters['entity_type'])
                ->where('entity_id', $entityModel ? HashIdFilter::decode($filters['entity_id'], $entityModel) ?? 0 : 0);
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn (Builder $b) => $b
                ->where('inspection_number', SearchOperator::like(), $term)
                ->orWhereHas('product', fn (Builder $p) => $p
                    ->where('part_number', SearchOperator::like(), $term)
                    ->orWhere('name', SearchOperator::like(), $term))
                ->orWhereHas('item', fn (Builder $i) => $i
                    ->where('code', SearchOperator::like(), $term)
                    ->orWhere('name', SearchOperator::like(), $term))
                ->orWhereHas('workOrderOutput', fn (Builder $output) => $output
                    ->where('batch_code', SearchOperator::like(), $term)
                    ->orWhereHas('workOrder', fn (Builder $wo) => $wo->where('wo_number', SearchOperator::like(), $term)))
                ->orWhere(fn (Builder $source) => $source->where('entity_type', 'work_order')->whereIn('entity_id', WorkOrder::query()->select('id')->where('wo_number', SearchOperator::like(), $term)))
                ->orWhere(fn (Builder $source) => $source->where('entity_type', 'grn')->whereIn('entity_id', GoodsReceiptNote::query()->select('id')->where('grn_number', SearchOperator::like(), $term)))
                ->orWhere(fn (Builder $source) => $source->where('entity_type', 'return_request')->whereIn('entity_id', ReturnRequest::query()->select('id')->where('rma_number', SearchOperator::like(), $term))));
        }

        // A finish-first WO is one review task even when it has several output
        // batches. Rank only rows surviving caller filters so status tabs and
        // queue counts retain their expected meaning.
        $ranked = (clone $q)
            ->whereJsonContains('inspections.counted_capture->workflow', 'finish_first_v1')
            ->where(function ($capture): void {
                $capture->where('inspections.counted_capture->version', 2)
                    ->orWhere(function ($confirmed): void {
                        $confirmed->where('inspections.counted_capture->version', 3)->where('inspections.counted_capture->evidence_method', 'confirmed_checks');
                    });
            })
            ->reorder()
            ->select('inspections.id')
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY inspections.entity_id ORDER BY CASE WHEN inspections.status IN ('draft', 'in_progress') THEN 0 WHEN inspections.status = 'awaiting_review' THEN 1 WHEN inspections.status = 'failed' THEN 2 WHEN inspections.status = 'passed' THEN 3 ELSE 4 END, inspections.id) AS qc_rank");
        $representativeIds = DB::query()
            ->fromSub($ranked, 'finish_first_qc_queue')
            ->where('qc_rank', 1)
            ->select('id');
        $q->where(function (Builder $rows) use ($representativeIds): void {
            $rows->where(function (Builder $legacy): void {
                $legacy->whereNull('inspections.counted_capture')
                    ->orWhere('inspections.counted_capture->workflow', '!=', 'finish_first_v1')
                    ->orWhereNotIn('inspections.counted_capture->version', [2, 3])
                    ->orWhere(function ($unknown): void {
                        $unknown->where('inspections.counted_capture->version', 3)->where(function ($method): void {
                            $method->whereNull('inspections.counted_capture->evidence_method')->orWhere('inspections.counted_capture->evidence_method', '!=', 'confirmed_checks');
                        });
                    });
            })->orWhereIn('inspections.id', $representativeIds);
        });

        return $q;
    }

    public function list(array $filters): LengthAwarePaginator
    {
        $page = $this->listQuery($filters)->orderByDesc('id')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 20), 100)));
        $finalInspections = $page->getCollection()->filter(fn ($row) => $row->usesFullPieceCapture());
        $summaries = app(WorkOrderFinalInspectionService::class)->summariesForWorkOrders(
            $finalInspections->pluck('entity_id')->unique()->all());
        foreach ($finalInspections as $inspection) {
            $inspection->setAttribute('work_order_quality', $summaries[$inspection->entity_id] ?? null);
        }
        return $page;
    }

    public function statusCounts(array $filters): array
    {
        unset($filters['status'], $filters['work_queue']);
        $counts = $this->listQuery($filters)->reorder()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return [
            'to_inspect' => (int) ($counts['draft'] ?? 0) + (int) ($counts['in_progress'] ?? 0),
            'awaiting_review' => (int) ($counts['awaiting_review'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'all' => (int) $counts->sum(),
        ];
    }

    /**
     * Model backing each polymorphic `entity_type`, for decoding `entity_id`
     * hashes on the list filter. Mirrors CreateInspectionRequest's map.
     *
     * @return class-string<Model>|null
     */
    private function entityModelClass(string $type): ?string
    {
        return match ($type) {
            InspectionEntityType::Grn->value => GoodsReceiptNote::class,
            InspectionEntityType::WorkOrder->value => WorkOrder::class,
            InspectionEntityType::Delivery->value => Delivery::class,
            InspectionEntityType::ReturnRequest->value => ReturnRequest::class,
            default => null,
        };
    }

    public function show(Inspection $inspection): Inspection
    {
        $inspection = $inspection->load([
            'product:id,part_number,name',
            'item:id,code,name,unit_of_measure',
            'inspector:id,name,role_id',
            'reviewer:id,name,role_id',
            'resultAuthors:id,inspection_id,user_id',
            'resultAuthors.user:id,name',
            'spec:id,product_id,version,is_active',
            'specRevision:id,inspection_spec_id,version,created_by,notes,capture_method',
            'specRevision.creator:id,name,role_id',
            'specRevision.items',
            'grnItem:id,goods_receipt_note_id,item_id,quantity_received,quantity_accepted,received_uom_code,quantity_received_in_uom,received_uom_conversion_factor',
            'grnItem.item:id,unit_of_measure',
            'grnItem.grn:id,grn_number,status',
            'qualityPlan:id,item_id,vendor_id,version,sampling_method,capture_method',
            'workOrderOutput.workOrder:id,wo_number,product_id',
            'trialOutput',
            'ncr',
            'calibrationRecord:id,equipment_code,name,status',
            'measurements' => fn ($q) => $q->orderBy('sample_index')->orderBy('id'),
        ]);

        return $this->attachEntityContext($inspection);
    }

    /** Create a lightweight, auditable incoming inspection for a raw item. */
    public function createIncomingForItem(
        Item $item,
        int $batchQuantity,
        int $grnId,
        ?User $by = null,
        ?string $notes = null,
        ?int $grnItemId = null,
    ): Inspection {
        if ($batchQuantity < 1) {
            throw new BusinessRuleException('Incoming inspection requires a positive batch quantity.');
        }
        $plan = AqlSampleSizeService::forBatch($batchQuantity);
        $sampleSize = (int) $plan['sample_size'];
        $checklist = (bool) config('inspection.checklist_capture_enabled');
        $line = $grnItemId ? GrnItem::query()->findOrFail($grnItemId) : null;
        $sourceQuantity = $line ? (string) $line->quantity_received : (string) $batchQuantity;
        $lot = $checklist && strcasecmp((string) $item->unit_of_measure, 'PCS') !== 0;
        if ($checklist && (! $line || (int) $line->goods_receipt_note_id !== $grnId || (int) $line->item_id !== $item->id)) {
            throw new BusinessRuleException('Incoming checklist requires its exact receipt line and item.');
        }
        if ($checklist && ! $lot && (bccomp($sourceQuantity, bcdiv($sourceQuantity, '1', 0), 3) !== 0
            || bccomp($sourceQuantity, '2147483647', 3) > 0)) {
            throw new BusinessRuleException('Piece receipts require a supported whole-number quantity.');
        }
        if ($checklist && ! $lot) {
            $batchQuantity = (int) $sourceQuantity;
            $plan = ['code' => null, 'accept' => $batchQuantity - 1, 'reject' => $batchQuantity];
            $sampleSize = $batchQuantity;
        }
        if ($lot) {
            $batchQuantity = 1;
            $sampleSize = 1;
        }

        return DB::transaction(function () use ($item, $batchQuantity, $grnId, $by, $notes, $plan, $sampleSize, $grnItemId, $checklist, $lot, $sourceQuantity) {
            $inspection = Inspection::query()->create([
                'inspection_number' => $this->sequences->generate('inspection'),
                'stage' => InspectionStage::Incoming->value,
                'status' => InspectionStatus::Draft->value,
                'inspection_mode' => InspectionMode::LotChecklist->value,
                'product_id' => null,
                'item_id' => $item->id,
                'capture_method' => $checklist ? 'checklist' : 'measurement',
                'source_quantity' => $checklist ? $sourceQuantity : null,
                'source_uom_code' => $checklist ? $item->unit_of_measure : null,
                'sampling_basis' => $checklist ? ($lot ? 'lot' : 'piece') : null,
                'entity_type' => InspectionEntityType::Grn->value,
                'entity_id' => $grnId,
                'grn_item_id' => $grnItemId,
                'batch_quantity' => $batchQuantity,
                'sample_size' => $sampleSize,
                'aql_code' => $plan['code'],
                'accept_count' => (int) $plan['accept'],
                'reject_count' => (int) $plan['reject'],
                'defect_count' => 0,
                'inspector_id' => $by?->id,
                'notes' => $notes,
            ]);

            // For lot-checklist mode, create the default checklist rows instead of
            // a sample_size × 1 matrix. Each checklist item gets one row with
            // sample_index = 1 (not variable by sample).
            $defaultChecklist = $this->settings->get('quality.incoming.default_checklist', []);
            if ($checklist) {
                $defaultChecklist = ! $lot ? [[
                    'parameter_name' => 'Full piece count',
                    'acceptance_criterion' => 'Count every received piece. Record all defective pieces and segregate them.',
                    'parameter_type' => 'visual',
                    'is_critical' => false,
                ]] : ChecklistDefinition::validate(array_map(fn ($check) => [
                    'parameter_name' => $check['parameter_name'] ?? '',
                    'acceptance_criterion' => $check['acceptance_criterion'] ?? $check['parameter_name'] ?? '',
                    'parameter_type' => $check['parameter_type'] ?? null,
                    'is_critical' => (bool) ($check['is_critical'] ?? false),
                ], (array) $defaultChecklist));
            }
            $timestamp = now()->toDateTimeString();
            $checklistRows = [];

            foreach ((array) $defaultChecklist as $check) {
                // Validate and skip entries with missing/empty parameter names.
                $paramName = trim((string) ($check['parameter_name'] ?? ''));
                if ($paramName === '') {
                    continue;
                }

                $checklistRows[] = [
                    'inspection_id' => $inspection->id,
                    'sample_index' => 1,
                    'parameter_name' => $paramName,
                    'acceptance_criterion' => $checklist || config('inspection.counted_capture_enabled') ? ($check['acceptance_criterion'] ?? $paramName) : null,
                    'parameter_type' => $checklist ? ($check['parameter_type'] ?? null) : InspectionParameterType::Visual->value,
                    'is_critical' => (bool) ($check['is_critical'] ?? false),
                    'is_pass' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            if ($checklistRows !== []) {
                InspectionMeasurement::query()->insert($checklistRows);
            }

            DB::table('goods_receipt_notes')
                ->where('id', $grnId)
                ->where(fn ($query) => $query->whereNull('qc_inspection_id')->orWhereIn('qc_inspection_id', Inspection::query()->where('status', 'cancelled')->select('id')))
                ->update(['qc_inspection_id' => $inspection->id, 'updated_at' => now()]);

            return $this->show($this->pinCountedCapture($inspection));
        });
    }

    /** One independently reviewed checklist for the exact physical return line. */
    public function createForReturnItem(ReturnRequestItem $line, ReturnRequest $return, User $by): Inspection
    {
        ChecklistDefinition::assertEnabled();

        return DB::transaction(function () use ($line, $return, $by): Inspection {
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            $line = ReturnRequestItem::query()->with(['item', 'product'])->lockForUpdate()->findOrFail($line->id);
            if ((int) $line->return_request_id !== (int) $return->id || (! $line->item && ! $line->product)
                || $return->finance_only || ! in_array($return->status->value, ['received', 'inspected'], true)) {
                throw new BusinessRuleException('Return checklist requires its received stock item.');
            }
            $existing = Inspection::query()->where('return_request_item_id', $line->id)
                ->where('stage', $return->type->value)->where('status', '!=', 'cancelled')->first();
            if ($existing) {
                return $this->show($existing);
            }
            $quantity = $line->receipt_recorded || bccomp((string) $line->returned_quantity, '0', 3) > 0
                ? (string) $line->returned_quantity : (string) $line->quantity;
            $unit = (string) ($line->product?->unit_of_measure ?? $line->item->unit_of_measure);
            $piece = strcasecmp($unit, 'PCS') === 0;
            if (bccomp($quantity, '0', 3) <= 0 || ($piece && (bccomp($quantity, bcdiv($quantity, '1', 0), 3) !== 0
                || bccomp($quantity, '2147483647', 3) > 0))) {
                throw new BusinessRuleException('Return inspection requires a positive quantity; pieces must be whole numbers.');
            }
            $spec = $line->product_id ? InspectionSpec::query()->where('product_id', $line->product_id)
                ->where('is_active', true)->with('currentRevision')->first() : null;
            if ($line->product_id && (! $spec?->currentRevision || $spec->currentRevision->items->isEmpty())) {
                throw new BusinessRuleException('Returned product needs an active published inspection specification.');
            }
            $plan = $line->product_id ? null : ItemQualityPlan::query()->where('item_id', $line->item_id)->effective()
                ->where(fn ($query) => $query->whereNull('vendor_id')->orWhere('vendor_id', $return->vendor_id))
                ->orderByRaw('vendor_id IS NULL')->orderByDesc('version')->first();
            $parameters = $spec ? $spec->currentRevision->items->map(fn ($row) => [...$row->toArray(),
                'inspection_spec_item_id' => $row->id,
                'acceptance_criterion' => ChecklistDefinition::criterion($row->toArray()),
            ])->all() : ($plan ? array_map(fn ($row) => [...$row, 'acceptance_criterion' => ChecklistDefinition::criterion($row)], (array) $plan->parameters)
                : array_map(fn ($row) => [
                    'parameter_name' => $row['parameter_name'] ?? '',
                    'acceptance_criterion' => $row['acceptance_criterion'] ?? $row['parameter_name'] ?? '',
                    'parameter_type' => $row['parameter_type'] ?? null,
                    'is_critical' => (bool) ($row['is_critical'] ?? false),
                ], (array) $this->settings->get('quality.incoming.default_checklist', [])));
            $sourceItemIds = array_column($parameters, 'inspection_spec_item_id');
            $parameters = ChecklistDefinition::validate($parameters, 'items', true);
            foreach ($parameters as $index => &$parameter) {
                $parameter['inspection_spec_item_id'] = $sourceItemIds[$index] ?? null;
            }
            unset($parameter);
            $inspection = Inspection::create([
                'inspection_number' => $this->sequences->generate('inspection'),
                'stage' => $return->type->value, 'status' => 'draft',
                'inspection_mode' => InspectionMode::LotChecklist->value, 'capture_method' => 'checklist',
                'product_id' => $line->product_id, 'item_id' => $line->item_id, 'item_quality_plan_id' => $plan?->id,
                'inspection_spec_id' => $spec?->id, 'inspection_spec_revision_id' => $spec?->currentRevision?->id,
                'return_request_item_id' => $line->id,
                'entity_type' => InspectionEntityType::ReturnRequest->value, 'entity_id' => $return->id,
                'source_quantity' => $quantity, 'source_uom_code' => $unit, 'sampling_basis' => $piece ? 'piece' : 'lot',
                'batch_quantity' => $piece ? (int) $quantity : 1, 'sample_size' => $piece ? (int) $quantity : 1,
                'accept_count' => 0, 'reject_count' => 1, 'defect_count' => 0, 'inspector_id' => $by->id,
                'notes' => 'Auto-created from RMA '.$return->rma_number,
            ]);
            $this->scaffoldLotChecklist($inspection, $parameters, $this->measuredPieces());

            return $this->show($this->pinCountedCapture($inspection));
        });
    }

    /** Create an incoming inspection from the effective item/vendor quality-plan revision. */
    public function createIncomingFromPlan(
        ItemQualityPlan $qualityPlan,
        GrnItem $line,
        GoodsReceiptNote $grn,
        ?User $by = null,
    ): Inspection {
        $checklist = config('inspection.checklist_capture_enabled') || $qualityPlan->capture_method === 'checklist';
        if ($checklist) {
            ChecklistDefinition::assertEnabled();
        }
        $line->loadMissing('item');
        $unit = (string) $line->item->unit_of_measure;
        $lot = $checklist && strcasecmp($unit, 'PCS') !== 0;
        $qtyReceived = (string) $line->quantity_received;
        if ($checklist && ((int) $line->goods_receipt_note_id !== $grn->id || (int) $qualityPlan->item_id !== $line->item_id
            || ($qualityPlan->vendor_id !== null && (int) $qualityPlan->vendor_id !== (int) $grn->vendor_id))) {
            throw new BusinessRuleException('Incoming checklist plan must match the receipt item and supplier.');
        }
        if ($checklist && ! $lot && (bccomp($qtyReceived, bcdiv($qtyReceived, '1', 0), 3) !== 0
            || bccomp($qtyReceived, '2147483647', 3) > 0)) {
            throw new BusinessRuleException('Piece receipts require a supported whole-number quantity.');
        }
        if (bccomp($qtyReceived, '0', 3) <= 0) {
            throw new BusinessRuleException('Incoming inspection requires a positive received quantity.');
        }
        $truncated = (int) bcdiv($qtyReceived, '1', 0);
        $batchQuantity = $lot ? 1 : (bccomp($qtyReceived, (string) $truncated, 3) > 0
            ? $truncated + 1
            : max(1, $truncated));
        $aql = AqlSampleSizeService::forBatch($batchQuantity);
        $sampleSize = match ($qualityPlan->sampling_method) {
            'full' => $checklist ? $batchQuantity : $this->boundedFullSampleSize($batchQuantity),
            'fixed' => min($batchQuantity, max(1, (int) $qualityPlan->fixed_sample_size)),
            default => (int) $aql['sample_size'],
        };
        if ($lot) {
            $batchQuantity = 1;
            $sampleSize = 1;
        }
        $accept = $qualityPlan->sampling_method === 'aql' ? (int) $aql['accept'] : 0;
        $reject = $qualityPlan->sampling_method === 'aql' ? (int) $aql['reject'] : 1;

        return DB::transaction(function () use ($qualityPlan, $line, $grn, $by, $batchQuantity, $sampleSize, $aql, $accept, $reject, $checklist, $lot, $unit, $qtyReceived) {
            $inspection = Inspection::query()->create([
                'inspection_number' => $this->sequences->generate('inspection'),
                'stage' => InspectionStage::Incoming->value,
                'status' => InspectionStatus::Draft->value,
                'inspection_mode' => InspectionMode::LotChecklist->value,
                'item_id' => $line->item_id,
                'item_quality_plan_id' => $qualityPlan->id,
                'capture_method' => $checklist ? 'checklist' : 'measurement',
                'source_quantity' => $checklist ? $qtyReceived : null,
                'source_uom_code' => $checklist ? $unit : null,
                'sampling_basis' => $checklist ? ($lot ? 'lot' : 'piece') : null,
                'entity_type' => InspectionEntityType::Grn->value,
                'entity_id' => $grn->id,
                'grn_item_id' => $line->id,
                'batch_quantity' => $batchQuantity,
                'sample_size' => $sampleSize,
                'aql_code' => $qualityPlan->sampling_method === 'aql' ? (string) $aql['code'] : null,
                'accept_count' => $accept,
                'reject_count' => $reject,
                'defect_count' => 0,
                'inspector_id' => $by?->id,
                'notes' => "Quality plan v{$qualityPlan->version}; GRN {$grn->grn_number}.",
            ]);

            $parameters = [];

            foreach ((array) $qualityPlan->parameters as $parameter) {
                $parameters[] = [
                    'parameter_name' => trim((string) ($parameter['parameter_name'] ?? '')),
                    'parameter_type' => $parameter['parameter_type'] ?? null,
                    'acceptance_criterion' => $checklist ? ChecklistDefinition::criterion($parameter) : ($parameter['acceptance_criterion'] ?? null),
                    'unit_of_measure' => $parameter['unit_of_measure'] ?? null,
                    'nominal_value' => $parameter['nominal_value'] ?? null,
                    'tolerance_min' => $parameter['tolerance_min'] ?? null,
                    'tolerance_max' => $parameter['tolerance_max'] ?? null,
                    'is_critical' => (bool) ($parameter['is_critical'] ?? false),
                    'notes' => $parameter['notes'] ?? null,
                    'inspection_spec_item_id' => null,
                ];
            }

            $this->scaffoldLotChecklist($inspection, $parameters, $this->measuredPieces());

            GoodsReceiptNote::query()->whereKey($grn->id)->where(fn ($query) => $query->whereNull('qc_inspection_id')->orWhereIn('qc_inspection_id', Inspection::query()->where('status', 'cancelled')->select('id')))
                ->update(['qc_inspection_id' => $inspection->id, 'updated_at' => now()]);

            return $this->show($this->pinCountedCapture($inspection));
        });
    }

    /**
     * Create the outgoing inspection for one good output batch — the ONE
     * creation path shared by TriggerOutgoingQC and the
     * qc:sweep-missing-outgoing repair, so both produce identical rows and
     * identical refusals (missing spec, missing revision, unmeasurable
     * product). Idempotent under the (stage, work_order_output_id) partial
     * unique index: a concurrent creator wins, the loser re-reads and gets
     * the existing row instead of a 500.
     *
     * The caller is responsible for the WO lifecycle guards (status,
     * SO-linkage) and for holding the WO lock.
     */
    public function createForHeldOutput(WorkOrderOutput $output, WorkOrder $wo, User $by): Inspection
    {
        if ($output->held_quantity < 1 || $output->work_order_id !== $wo->id) {
            throw new BusinessRuleException('Historical QC requires the actual held output batch.');
        }
        $existing = Inspection::query()->where('stage', 'in_process')->where('work_order_output_id', $output->id)->first();
        if ($existing) {
            return $existing;
        }
        $spec = InspectionSpec::query()->where('product_id', $wo->product_id)->where('is_active', true)->with(['items', 'currentRevision'])->first();
        if (! $spec) {
            throw new BusinessRuleException('QC must publish an inspection specification before this held batch can be approved.');
        }
        app(InspectionSpecService::class)->assertInspectable($spec);
        $inspection = Inspection::create([
            'inspection_number' => $this->sequences->generate('inspection'), 'stage' => 'in_process', 'status' => 'draft',
            'entity_type' => 'work_order', 'entity_id' => $wo->id, 'work_order_output_id' => $output->id,
            'product_id' => $wo->product_id, 'inspection_spec_id' => $spec->id, 'inspection_spec_revision_id' => $spec->currentRevision->id,
            'batch_quantity' => $output->held_quantity, 'sample_size' => min($output->held_quantity, $this->measuredPieces()),
            'source_quantity' => (string) $output->held_quantity, 'source_uom_code' => $wo->product->unit_of_measure ?: 'PCS',
            'sampling_basis' => 'piece', 'capture_method' => 'measurement', 'inspection_mode' => 'lot_checklist',
            'accept_count' => 0, 'reject_count' => 1, 'defect_count' => 0,
            'counted_capture' => ['version' => 1, 'result_policy' => 'zero_defects', 'required_quantity' => $output->held_quantity],
            'notes' => 'Production occurred before its required quality release. Full batch inspection and process recovery are required.',
        ]);
        $parameters = $spec->items->map(fn ($item) => array_merge($item->toArray(), [
            'inspection_spec_item_id' => $item->id, 'acceptance_criterion' => ChecklistDefinition::criterion($item->toArray()),
        ]))->all();
        $this->scaffoldLotChecklist($inspection, $parameters, $inspection->sample_size);
        app(NcrService::class)->create([
            'source' => 'inspection_fail', 'severity' => 'high', 'product_id' => $wo->product_id,
            'inspection_id' => $inspection->id, 'affected_quantity' => $output->held_quantity,
            'defect_description' => 'Process deviation: physical production preceded required quality release. Inspect and reconcile this entire held batch.',
        ], $by);

        return $inspection;
    }

    public function createForOutput(WorkOrderOutput $output, WorkOrder $workOrder, User $by): Inspection
    {
        if ($output->trial_inspection_id && ! $output->trial_settled_at) {
            throw new BusinessRuleException('Unsettled trial pieces cannot receive outgoing QC.');
        }
        $guard = ['stage' => InspectionStage::Outgoing->value, 'work_order_output_id' => $output->id];

        $existing = Inspection::query()->where($guard)->where('status', '!=', InspectionStatus::Cancelled->value)->first();
        if ($existing) {
            return $existing;
        }

        if ($workOrder->usesFinishFirstQc()) {
            return $this->createFullPieceOutputInspection($output, $workOrder, $by);
        }

        try {
            return $this->create([
                'stage' => InspectionStage::Outgoing->value,
                'product_id' => (int) $workOrder->product_id,
                'batch_quantity' => (int) $output->good_count,
                'entity_type' => InspectionEntityType::WorkOrder->value,
                'entity_id' => (int) $workOrder->id,
                'work_order_output_id' => (int) $output->id,
            ], $by);
        } catch (QueryException $e) {
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }

            $winner = Inspection::query()->where($guard)->where('status', '!=', InspectionStatus::Cancelled->value)->first();
            if (! $winner) {
                throw $e;
            }

            return $winner;
        }
    }

    private function createFullPieceOutputInspection(WorkOrderOutput $output, WorkOrder $workOrder, User $by): Inspection
    {
        $closedCorrection = $workOrder->status->value === 'closed'
            && $this->isAuthorizedClosedCorrection($output, $workOrder, $by);
        if (($workOrder->status->value !== 'completed' && ! $closedCorrection)
            || (int) $output->work_order_id !== (int) $workOrder->id) {
            throw new BusinessRuleException('Final QC can be created only for output from a physically completed work order.');
        }
        if ($output->rework_ncr_id !== null && ! DB::table('non_conformance_reports')
            ->where('id', $output->rework_ncr_id)->whereNotNull('rework_finished_at')->exists()) {
            throw new BusinessRuleException('Finish inline repair before creating its final QC inspection.');
        }
        // Inline repair outputs may include pieces already scrapped at the
        // repair station. Only accepted repairs re-enter saleable QC/stock.
        $quantity = $this->fullPieceOutputQuantity($output);
        if ($quantity < 1) {
            throw new BusinessRuleException('Final QC requires a positive physical output quantity.');
        }
        $spec = InspectionSpec::query()->where('product_id', $workOrder->product_id)
            ->where('is_active', true)->with(['currentRevision.items'])->first();
        app(InspectionSpecService::class)->assertInspectable($spec);
        $references = $spec?->currentRevision?->items ?? collect();
        if (! $spec?->currentRevision || $references->isEmpty()) {
            throw new BusinessRuleException('QC must publish an inspection specification before this output can be inspected.');
        }
        if ($references->contains(fn (InspectionSpecItem $item): bool => ChecklistDefinition::criterion($item->toArray()) === '')) {
            throw new BusinessRuleException('QC must publish acceptance criteria for every final inspection requirement.');
        }
        $inspection = Inspection::query()->create([
            'inspection_number' => $this->sequences->generate('inspection'),
            'stage' => InspectionStage::Outgoing->value,
            'status' => InspectionStatus::Draft->value,
            'inspection_mode' => InspectionMode::LotChecklist->value,
            'capture_method' => 'measurement',
            'source_quantity' => (string) $quantity,
            'source_uom_code' => $workOrder->product->unit_of_measure ?: 'PCS',
            'sampling_basis' => 'piece',
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $workOrder->id,
            'work_order_output_id' => $output->id,
            'product_id' => $workOrder->product_id,
            'inspection_spec_id' => $spec->id,
            'inspection_spec_revision_id' => $spec->currentRevision->id,
            'batch_quantity' => $quantity,
            'accepted_quantity' => 0,
            'sample_size' => $quantity,
            'accept_count' => 0,
            'reject_count' => 1,
            'defect_count' => 0,
            'started_at' => now(),
            'counted_capture' => [
                'version' => config('inspection.outgoing_confirmed_checks_enabled') ? 3 : 2,
                ...(config('inspection.outgoing_confirmed_checks_enabled') ? ['evidence_method' => 'confirmed_checks'] : []),
                'workflow' => 'finish_first_v1',
                'result_policy' => 'zero_defects',
                'required_quantity' => $quantity,
            ],
        ]);
        $inspection->forceFill(['sample_defect_count' => 0])->save();
        $parameters = $references->map(fn (InspectionSpecItem $item): array => [
            ...$item->toArray(),
            'inspection_spec_item_id' => $item->id,
            'acceptance_criterion' => ChecklistDefinition::criterion($item->toArray()),
        ])->all();
        $this->scaffoldLotChecklist($inspection, $parameters, 1);

        return $inspection;
    }

    public function createConfirmedReplacement(Inspection $original, WorkOrder $wo, User $by, string $reason): Inspection
    {
        if (! $by->is_active || ! $by->hasPermission('quality.inspections.manage')) {
            throw new ForbiddenActionException('Active authorized Quality user required.');
        }
        if (! config('inspection.outgoing_confirmed_checks_enabled') || trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw new BusinessRuleException('Confirmed checks are unavailable or the replacement reason is missing.');
        }
        return DB::transaction(function () use ($original, $wo, $reason): Inspection {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            $output = WorkOrderOutput::query()->lockForUpdate()->findOrFail($original->work_order_output_id);
            $original = Inspection::query()->lockForUpdate()->findOrFail($original->id);
            $wo->assertFinishFirstRoutingCompatible();
            if (! $wo->usesFinishFirstQc() || $wo->status->value !== 'completed'
                || ! $original->isUntouchedFullPieceDraft()
                || (int) $output->work_order_id !== (int) $wo->id
                || $original->stage !== InspectionStage::Outgoing || $original->entity_type !== InspectionEntityType::WorkOrder
                || (int) $original->entity_id !== (int) $wo->id || (int) $original->product_id !== (int) $wo->product_id
                || (int) $original->batch_quantity < 1
                || (int) ($original->counted_capture['required_quantity'] ?? 0) !== (int) $original->batch_quantity
                || ($original->counted_capture['result_policy'] ?? null) !== 'zero_defects'
                || ($output->rework_ncr_id && ! \App\Modules\Quality\Models\NonConformanceReport::query()
                    ->whereKey($output->rework_ncr_id)->whereNotNull('rework_finished_at')->exists())
                || $this->fullPieceOutputQuantity($output) !== (int) $original->batch_quantity
                || bccomp((string) $original->source_quantity, (string) $original->batch_quantity, 3) !== 0
                || strcasecmp((string) $original->source_uom_code, (string) $wo->product->unit_of_measure) !== 0) {
                throw new BusinessRuleException('Only untouched final QC with unchanged completed production can switch methods.');
            }
            $revision = $original->specRevision()->with('items')->firstOrFail();
            if ((int) $revision->inspection_spec_id !== (int) $original->inspection_spec_id || $revision->items->isEmpty()) {
                throw new BusinessRuleException('QC must preserve its published inspection specification.');
            }
            $source = $original->only(['stage', 'inspection_mode', 'product_id', 'item_id', 'inspection_spec_id',
                'inspection_spec_revision_id', 'entity_type', 'entity_id', 'work_order_output_id', 'batch_quantity',
                'sample_size', 'accept_count', 'reject_count', 'capture_method', 'source_quantity', 'source_uom_code', 'sampling_basis']);
            $original->forceFill(['status' => InspectionStatus::Cancelled, 'result_version' => (int) $original->result_version + 1,
                'notes' => trim(($original->notes ?? '')."\nReplacement reason: ".trim($reason))])->save();
            $next = Inspection::create([...$source, 'inspection_number' => $this->sequences->generate('inspection'),
                'status' => InspectionStatus::Draft, 'result_version' => 0, 'replaces_inspection_id' => $original->id,
                'started_at' => now(), 'accepted_quantity' => 0, 'defect_count' => 0,
                'notes' => 'Replaces '.$original->inspection_number.': '.trim($reason),
                'counted_capture' => ['version' => 3, 'workflow' => 'finish_first_v1', 'evidence_method' => 'confirmed_checks',
                    'required_quantity' => (int) $original->batch_quantity, 'result_policy' => 'zero_defects']]);
            $next->forceFill(['sample_defect_count' => 0])->save();
            $parameters = $revision->items->map(fn (InspectionSpecItem $item): array => [
                ...$item->toArray(), 'inspection_spec_item_id' => $item->id,
                'acceptance_criterion' => ChecklistDefinition::criterion($item->toArray()),
            ])->all();
            $this->scaffoldLotChecklist($next, $parameters, 1);
            return $next;
        }, 3);
    }

    private function isAuthorizedClosedCorrection(WorkOrderOutput $output, WorkOrder $workOrder, User $by): bool
    {
        $key = (string) $output->idempotency_key;
        if (! preg_match('/^late-report:(\d+)$/', $key, $match)) {
            return false;
        }
        $report = LateProductionReport::query()->find((int) $match[1]);

        return $report !== null && $report->status?->value === 'approved'
            && (int) $report->work_order_id === (int) $workOrder->id
            && (int) $report->work_order_output_id === (int) $output->id
            && (int) $report->reviewed_by === (int) $by->id
            && trim((string) $report->review_reason) !== '';
    }

    private function isApprovedClosedCorrectionSource(WorkOrderOutput $output, WorkOrder $workOrder): bool
    {
        $key = (string) $output->idempotency_key;
        if (! preg_match('/^late-report:(\d+)$/', $key, $match)) {
            return false;
        }
        $report = LateProductionReport::query()->find((int) $match[1]);

        return $report !== null && $report->status?->value === 'approved'
            && (int) $report->work_order_id === (int) $workOrder->id
            && (int) $report->work_order_output_id === (int) $output->id
            && trim((string) $report->review_reason) !== '';
    }

    private function fullPieceOutputQuantity(WorkOrderOutput $output): int
    {
        return $output->rework_ncr_id !== null
            ? (int) $output->good_count
            : (int) $output->physical_count;
    }

    /**
     * Open a draft inspection, applying the AQL plan for outgoing batches.
     *
     * @param  array<string, mixed>  $data  {
     *                                      stage, product_id, batch_quantity, entity_type?, entity_id?, notes?
     *                                      }
     */
    public function create(array $data, User $by): Inspection
    {
        return DB::transaction(fn () => $this->createLocked($data, $by));
    }

    private function createLocked(array $data, User $by): Inspection
    {
        if (($data['entity_type'] ?? null) === InspectionEntityType::WorkOrder->value) {
            WorkOrder::query()->lockForUpdate()->findOrFail($data['entity_id'] ?? 0);
        } elseif (($data['entity_type'] ?? null) === InspectionEntityType::ReturnRequest->value) {
            ReturnRequest::query()->lockForUpdate()->findOrFail($data['entity_id'] ?? 0);
        } elseif (! empty($data['work_order_output_id'])) {
            $sourceOutput = WorkOrderOutput::query()->findOrFail($data['work_order_output_id']);
            WorkOrder::query()->lockForUpdate()->findOrFail($sourceOutput->work_order_id);
        }
        $stage = InspectionStage::from((string) $data['stage']);
        $productId = (int) $data['product_id'];
        $batchQty = (int) $data['batch_quantity'];
        if (! empty($data['wo_operation_id'])) {
            if (($data['entity_type'] ?? null) !== InspectionEntityType::WorkOrder->value) {
                throw new BusinessRuleException('Operation QC requires actual output from its matching work order.');
            }
            $operation = WoOperation::query()->lockForUpdate()->findOrFail($data['wo_operation_id']);
            $source = WorkOrder::query()->findOrFail($operation->work_order_id);
            $actual = (string) $operation->qty_completed;
            if ($stage !== InspectionStage::InProcess || ($data['entity_type'] ?? null) !== InspectionEntityType::WorkOrder->value
                || (int) ($data['entity_id'] ?? 0) !== (int) $operation->work_order_id || (int) $source->product_id !== $productId
                || in_array($source->status->value, ['closed', 'cancelled'], true)
                || bccomp($actual, '0', 4) <= 0 || bccomp($actual, bcdiv($actual, '1', 0), 4) !== 0
                || bccomp($actual, '2147483647', 4) > 0) {
                throw new BusinessRuleException('Operation QC requires actual positive whole output from its matching open work order and product.');
            }
            $batchQty = (int) $actual;
        }
        if ($batchQty < 1) {
            throw new BusinessRuleException('Inspection requires a positive batch quantity.');
        }

        $product = Product::query()->lockForUpdate()->findOrFail($productId);
        $output = null;
        if ($stage === InspectionStage::Outgoing) {
            $outputId = (int) ($data['work_order_output_id'] ?? 0);
            if ($outputId < 1) {
                throw new BusinessRuleException('Outgoing inspection requires a specific work-order output batch.');
            }

            $output = WorkOrderOutput::query()->with('workOrder')->findOrFail($outputId);
            if ($output->workOrder?->usesFinishFirstQc()) {
                throw new BusinessRuleException('Final inspection is created only by the completed work-order QC handoff.');
            }
            if ($output->workOrder?->status?->value === 'cancelled') {
                throw new BusinessRuleException('Cancelled work-order output cannot receive new QC release evidence.');
            }
            if ($output->trial_inspection_id && ! $output->trial_settled_at) {
                throw new BusinessRuleException('Unsettled trial pieces cannot receive outgoing QC.');
            }
            if ((int) $output->good_count < 1) {
                throw new BusinessRuleException('Outgoing inspection requires a positive good output quantity.');
            }
            if ((int) $output->workOrder?->product_id !== $product->id) {
                throw new BusinessRuleException('Outgoing inspection product does not match the work-order output.');
            }

            $entityType = (string) ($data['entity_type'] ?? InspectionEntityType::WorkOrder->value);
            $entityId = (int) ($data['entity_id'] ?? $output->work_order_id);
            if ($entityType !== InspectionEntityType::WorkOrder->value || $entityId !== (int) $output->work_order_id) {
                throw new BusinessRuleException('Outgoing inspection must target the work order that produced the output batch.');
            }

            // The physical output is authoritative; callers cannot inspect a
            // different quantity while claiming provenance for this batch.
            $batchQty = (int) $output->good_count;
            $data['entity_type'] = InspectionEntityType::WorkOrder->value;
            $data['entity_id'] = $output->work_order_id;
        }
        $spec = InspectionSpec::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->with(['items', 'currentRevision'])
            ->first();

        if (! $spec) {
            throw new BusinessRuleException("Product {$product->part_number} has no active inspection spec.");
        }
        if ($spec->items->isEmpty()) {
            throw new BusinessRuleException("Inspection spec for {$product->part_number} has no parameters.");
        }
        if (! $spec->currentRevision) {
            throw new BusinessRuleException("Inspection spec for {$product->part_number} has no immutable revision.");
        }

        $calibrationRecordId = isset($data['calibration_record_id']) && (int) $data['calibration_record_id'] > 0
            ? (int) $data['calibration_record_id']
            : null;
        if ($calibrationRecordId !== null) {
            $record = CalibrationRecord::query()->findOrFail($calibrationRecordId);
            if (in_array($record->status, [CalibrationStatus::Overdue, CalibrationStatus::Retired], true)) {
                throw new BusinessRuleException(
                    "Equipment {$record->equipment_code} is {$record->status->value} and cannot be used for inspection."
                );
            }
        }

        // Automatic in-process/outgoing listeners may already have opened the
        // inspection before the operator reaches this form. Reusing that row is
        // the safe idempotent result; inserting a second row would hit the
        // database uniqueness guard and surface as a misleading 500.
        // A CANCELLED row is not reused: its unique slot was released by
        // migration 0565, so a fresh inspection is created in its place.
        $existing = Inspection::query()
            ->when($stage === InspectionStage::Outgoing,
                fn ($query) => $query->where('work_order_output_id', $output?->id),
                fn ($query) => $query
                    ->where('stage', $stage->value)
                    ->where('product_id', $product->id)
                    ->where('entity_type', $data['entity_type'] ?? null)
                    ->where('entity_id', $data['entity_id'] ?? null)
                    ->where('wo_operation_id', $data['wo_operation_id'] ?? null)
                    ->where('work_order_output_id', $data['work_order_output_id'] ?? null),
            )
            ->where('status', '!=', InspectionStatus::Cancelled->value)
            ->first();
        if ($existing) {
            return $this->show($existing);
        }

        if ($output?->workOrder?->status?->value === 'closed') {
            throw new BusinessRuleException('This work order is closed. Report a late defect against its final QC record; repair uses a linked recovery work order.');
        }
        app(InspectionSpecService::class)->assertInspectable($spec);
        $checklist = config('inspection.checklist_capture_enabled') || $spec->currentRevision->capture_method === 'checklist';
        if ($checklist) {
            ChecklistDefinition::assertEnabled();
        }
        $sourceQuantity = (string) $batchQty;
        $samplingBasis = 'piece';
        if ($checklist && ($data['entity_type'] ?? null) === InspectionEntityType::WorkOrder->value && $stage !== InspectionStage::Outgoing) {
            $source = WorkOrder::query()->lockForUpdate()->findOrFail($data['entity_id'] ?? 0);
            if ($stage !== InspectionStage::InProcess || (int) $source->product_id !== $productId
                || in_array($source->status->value, ['closed', 'cancelled'], true)) {
                throw new BusinessRuleException('In-process checklist must match an open work order and its product.');
            }
            if (empty($data['wo_operation_id'])) {
                $batchQty = (int) $source->quantity_target;
            }
            $sourceQuantity = (string) $batchQty;
        }
        if ($checklist && ($data['entity_type'] ?? null) === InspectionEntityType::ReturnRequest->value) {
            $source = ReturnRequest::query()->lockForUpdate()->findOrFail($data['entity_id'] ?? 0);
            if ($stage->value !== $source->type->value || ! in_array($source->status->value, ['received', 'inspected'], true)) {
                throw new BusinessRuleException('Return checklist must match a received return and its return type.');
            }
            $sourceQuantity = $this->receivedReturnQuantity($source, $productId);
            if (bccomp($sourceQuantity, '0', 3) <= 0) {
                throw new BusinessRuleException('Return has no received quantity for this product.');
            }
            $samplingBasis = strcasecmp((string) $product->unit_of_measure, 'PCS') === 0 ? 'piece' : 'lot';
            if ($samplingBasis === 'piece' && (bccomp($sourceQuantity, bcdiv($sourceQuantity, '1', 0), 3) !== 0
                || bccomp($sourceQuantity, '2147483647', 3) > 0)) {
                throw new BusinessRuleException('Piece returns require a supported whole-number received quantity.');
            }
            $batchQty = $samplingBasis === 'lot' ? 1 : (int) $sourceQuantity;
        }
        if ($checklist && ($data['entity_type'] ?? null) === InspectionEntityType::Grn->value) {
            throw new BusinessRuleException('Create incoming checklist from the exact receipt line in Warehouse.');
        }
        if ($checklist && ($data['entity_type'] ?? null) === InspectionEntityType::Delivery->value) {
            throw new BusinessRuleException('Outgoing checklist must refer to its production output, not a delivery.');
        }
        // AQL plan only applies to outgoing. In-process takes a small fixed
        // sample (quality.in_process.sample_size); other manual stages default
        // to 100% inspection of the batch.
        if ($stage === InspectionStage::Outgoing) {
            $plan = AqlSampleSizeService::forBatch($batchQty);
            $sample = $plan['sample_size'];
            $code = $plan['code'];
            $accept = $plan['accept'];
            $reject = $plan['reject'];
        } elseif ($stage === InspectionStage::InProcess) {
            // In-process QC samples a few pieces off the running line; the lot
            // itself is gated later by outgoing AQL. A 100% sample of the WO
            // target meant hundreds of rows before any output existed and, past
            // the full-sampling cap, no in-process inspection at all.
            $sample = min($batchQty, $this->settings->requiredInt('quality.in_process.sample_size', 1, 1000));
            $code = null;
            $accept = 0;
            $reject = 1;
        } else {
            $sample = $checklist ? $batchQty : $this->boundedFullSampleSize($batchQty);
            $code = null;
            $accept = 0;
            $reject = 1;
        }

        $startupTrial = false;
        if ($stage === InspectionStage::InProcess && ($data['entity_type'] ?? null) === InspectionEntityType::WorkOrder->value
            && empty($data['wo_operation_id']) && empty($data['work_order_output_id'])) {
            $source = WorkOrder::query()->findOrFail($data['entity_id']);
            $startupTrial = ! $source->parent_wo_id && ! $source->parent_ncr_id && ! $source->rework_source_output_id;
        }

        $lotChecklist = $checklist || in_array(
            $stage,
            [InspectionStage::Incoming, InspectionStage::InProcess, InspectionStage::Outgoing],
            true,
        );
        $measuredPieces = $lotChecklist ? $this->measuredPieces() : 0;

        return DB::transaction(function () use (
            $stage, $product, $spec, $batchQty, $sample, $code, $accept, $reject, $by, $data, $output, $calibrationRecordId, $lotChecklist, $measuredPieces, $checklist, $sourceQuantity, $samplingBasis, $startupTrial
        ) {
            $insp = Inspection::query()->create([
                'startup_trial_required' => $startupTrial,
                'capture_method' => $checklist ? 'checklist' : 'measurement',
                'source_quantity' => $checklist ? $sourceQuantity : null,
                'source_uom_code' => $checklist ? ($product->unit_of_measure ?: 'PCS') : null,
                'sampling_basis' => $checklist ? $samplingBasis : null,
                'inspection_number' => $this->sequences->generate('inspection'),
                'stage' => $stage->value,
                'status' => InspectionStatus::Draft->value,
                'inspection_mode' => ($lotChecklist ? InspectionMode::LotChecklist : InspectionMode::PerUnit)->value,
                'product_id' => $product->id,
                'inspection_spec_id' => $spec->id,
                'inspection_spec_revision_id' => $spec->currentRevision?->id,
                'entity_type' => isset($data['entity_type']) ? InspectionEntityType::from((string) $data['entity_type'])->value : null,
                'entity_id' => isset($data['entity_id']) ? (int) $data['entity_id'] : null,
                'wo_operation_id' => $data['wo_operation_id'] ?? null,
                'work_order_output_id' => $output?->id,
                'batch_quantity' => $batchQty,
                'accepted_quantity' => 0,
                'sample_size' => $sample,
                'aql_code' => $code,
                'accept_count' => $accept,
                'reject_count' => $reject,
                'defect_count' => 0,
                'inspector_id' => $by->id,
                'calibration_record_id' => $calibrationRecordId,
                'started_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);
            $insp->forceFill(['sample_defect_count' => 0])->save();

            if ($lotChecklist) {
                $parameters = [];

                foreach ($spec->items as $item) {
                    $parameters[] = [
                        'parameter_name' => trim((string) $item->parameter_name),
                        'parameter_type' => $item->parameter_type?->value,
                        'acceptance_criterion' => $checklist ? ChecklistDefinition::criterion($item->toArray()) : $item->acceptance_criterion,
                        'unit_of_measure' => $item->unit_of_measure,
                        'nominal_value' => $item->nominal_value,
                        'tolerance_min' => $item->tolerance_min,
                        'tolerance_max' => $item->tolerance_max,
                        'is_critical' => (bool) $item->is_critical,
                        'notes' => $item->notes,
                        'inspection_spec_item_id' => (int) $item->id,
                    ];
                }

                $this->scaffoldLotChecklist($insp, $parameters, $measuredPieces);
            } else {
                // Per-unit matrix: one row per (sample × spec_item), inserted in
                // bounded batches without holding the whole matrix in memory for
                // a large lot. The return stages still create these fresh.
                $this->insertScaffoldRows(
                    $insp->id,
                    $sample,
                    $spec->items,
                    static function (int $sampleIndex, InspectionSpecItem $item, int $inspectionId, string $timestamp): array {
                        return [
                            'inspection_id' => $inspectionId,
                            'inspection_spec_item_id' => $item->id,
                            'sample_index' => $sampleIndex,
                            'parameter_name' => $item->parameter_name,
                            'parameter_type' => $item->parameter_type->value,
                            'unit_of_measure' => $item->unit_of_measure,
                            'nominal_value' => $item->nominal_value,
                            'tolerance_min' => $item->tolerance_min,
                            'tolerance_max' => $item->tolerance_max,
                            'measured_value' => null,
                            'is_critical' => $item->is_critical,
                            'is_pass' => null,
                            'notes' => null,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    },
                );
            }

            // Back-link the inspection onto the gated entity so that
            // downstream services (GRN accept gate, delivery release gate)
            // can find it without a join.
            if ($insp->entity_type instanceof InspectionEntityType
                && $insp->entity_id) {
                $table = match ($insp->entity_type) {
                    InspectionEntityType::Grn => 'goods_receipt_notes',
                    default => null,
                };
                if ($table) {
                    DB::table($table)
                        ->where('id', $insp->entity_id)
                        ->whereNull('qc_inspection_id')
                        ->update(['qc_inspection_id' => $insp->id, 'updated_at' => now()]);
                }
            }

            return $this->show($this->pinCountedCapture($insp));
        });
    }

    /**
     * Patch measurement readings. Each input row is keyed by measurement id
     * and may set measured_value, is_pass, notes. Auto-evaluation overrides
     * the explicit is_pass for numeric parameters that have a tolerance band,
     * except on a lot-checklist piece row that carries no reading, where a
     * non-critical row may state its own attribute result.
     *
     * @param  array<int, array{measured_value?: float|string|null, is_pass?: bool|null, notes?: string|null}>  $rows
     */
    public function recordMeasurements(Inspection $inspection, array $rows, User $by): Inspection
    {
        $this->assertLegacyEntry($inspection);
        if ($inspection->usesChecklistCapture()) {
            throw new BusinessRuleException('Use checklist submission with confirmed coverage for this inspection.');
        }
        if ($inspection->status->isTerminal()) {
            throw new BusinessRuleException('Inspection is already finalised.');
        }
        if ($inspection->status === InspectionStatus::AwaitingReview) {
            throw new BusinessRuleException('Inspection is awaiting checker review and its evidence is locked.');
        }

        return DB::transaction(function () use ($inspection, $rows, $by) {
            app(WorkOrderOutputService::class)->lockTrialSource($inspection);
            // Route-bound inspection models can be stale when a completion or
            // cancellation races measurement entry. Serialize the lifecycle
            // on the inspection row before touching its measurements.
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);
            app(WorkOrderOutputService::class)->assertTrialEvidence($lockedInspection);
            if ($lockedInspection->startup_trial_required && (! $by->is_active || ! $by->hasPermission('quality.inspections.manage'))) {
                throw new ForbiddenActionException('Active authorized QC user required to record trial evidence.');
            }
            if ($lockedInspection->status->isTerminal()) {
                throw new BusinessRuleException('Inspection is already finalised.');
            }
            if ($lockedInspection->status === InspectionStatus::AwaitingReview) {
                throw new BusinessRuleException('Inspection is awaiting checker review and its evidence is locked.');
            }

            $measurements = InspectionMeasurement::query()
                ->where('inspection_id', $lockedInspection->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($rows === []) {
                throw new BusinessRuleException('At least one inspection measurement is required.');
            }

            $submittedIds = array_map(static fn ($id): int => (int) $id, array_keys($rows));
            $knownIds = $measurements->keys()->map(static fn ($id): int => (int) $id)->all();
            $unknownIds = array_values(array_diff($submittedIds, $knownIds));
            if ($unknownIds !== []) {
                throw new BusinessRuleException(
                    'The measurement payload contains rows that do not belong to this inspection.',
                );
            }

            $hasMeaningfulResultEdit = false;

            foreach ($rows as $id => $patch) {
                /** @var InspectionMeasurement|null $m */
                $m = $measurements->get((int) $id);
                if (! $m) {
                    throw new BusinessRuleException(
                        'The measurement payload contains rows that do not belong to this inspection.',
                    );
                }
                if (! is_array($patch)) {
                    throw new BusinessRuleException('Each inspection measurement patch must be an object.');
                }

                // Captured before the patch is written onto the model: a claim
                // may fill a blank row, never a row that already held a reading
                // in the same call that deletes it.
                $preExistingValue = $m->measured_value;

                if (array_key_exists('measured_value', $patch)) {
                    $m->measured_value = $patch['measured_value'] === '' || $patch['measured_value'] === null
                        ? null
                        : (string) $patch['measured_value'];
                    $hasMeaningfulResultEdit = $hasMeaningfulResultEdit || $m->isDirty('measured_value');
                }
                if (array_key_exists('notes', $patch)) {
                    $m->notes = $patch['notes'] !== '' ? $patch['notes'] : null;
                    $hasMeaningfulResultEdit = $hasMeaningfulResultEdit || $m->isDirty('notes');
                }

                if ($m->hasTolerance()) {
                    // A tolerance-backed parameter is evidence-bearing: where it
                    // has a reading, that reading decides, and an explicit result
                    // may not contradict it — a client able to assert a pass over
                    // an out-of-tolerance reading would be writing a forged
                    // quality record.
                    //
                    // The one claim a toleranced row may carry on its own is an
                    // attribute claim with no reading at all: "inspected,
                    // conforming", which is how a lot-checklist piece row is
                    // ticked without a measurement. A critical characteristic is
                    // always a measured value, and the legacy per-unit grid has no
                    // tick surface, so neither may assert one.
                    //
                    // "No reading" is judged on what the row held when the request
                    // arrived, not on what the patch just left behind: otherwise a
                    // single patch could delete the reading and assert conformance
                    // over the gap, turning a recorded failure into a pass.
                    $auto = $m->evaluate();
                    $claim = $patch['is_pass'] ?? null;
                    $mayCarryClaim = $claim !== null
                        && ! $m->is_critical
                        && ! ($lockedInspection->startup_trial_required && $m->requiresSizeReading())
                        && $lockedInspection->inspection_mode === InspectionMode::LotChecklist
                        && $preExistingValue === null;

                    if ($claim !== null
                        && ! $mayCarryClaim
                        && ($auto === null || (bool) $claim !== $auto)) {
                        throw new BusinessRuleException(
                            "Measurement {$m->parameter_name} requires a result derived from its measured value.",
                        );
                    }

                    $m->is_pass = $auto ?? ($mayCarryClaim ? (bool) $claim : null);
                    $hasMeaningfulResultEdit = $hasMeaningfulResultEdit || $m->isDirty('is_pass');
                } elseif (array_key_exists('is_pass', $patch)) {
                    // Manual visual/functional parameters use an explicit
                    // verdict because they have no tolerance window.
                    $m->is_pass = $patch['is_pass'] === null ? null : (bool) $patch['is_pass'];
                    $hasMeaningfulResultEdit = $hasMeaningfulResultEdit || $m->isDirty('is_pass');
                }

                $m->save();
            }

            if ($hasMeaningfulResultEdit) {
                $this->recordResultAuthor($lockedInspection, $by);
            }

            // Recompute defect_count and bump status to in_progress. A defect is
            // a defective SAMPLE UNIT (one Sample index), not a failed
            // parameter row: a single part failing three dimensions is one
            // defect against Ac, not three. Counting rows made a good lot fail
            // AQL whenever one sampled unit had multiple out-of-tolerance
            // measurements.
            $defects = InspectionMeasurement::query()
                ->where('inspection_id', $lockedInspection->id)
                ->where('is_pass', false)
                ->distinct()
                ->count('sample_index');

            $this->states->assertAllowed($lockedInspection, InspectionStatus::InProgress);
            $lockedInspection->forceFill([
                'defect_count' => $defects,
                'status' => InspectionStatus::InProgress->value,
                'inspector_id' => $lockedInspection->inspector_id ?? $by->id,
            ])->save();

            return $this->show($lockedInspection->fresh());
        });
    }

    /**
     * Record lot-checklist results: checklist verdicts, piece measurements, and defect count.
     *
     * @param  array{checklist?: list<array{measured_value?: float|string|null, is_pass?: bool|null, notes?: string|null}>, measurements?: list<array{measured_value?: float|string|null}>, sample_defect_count?: ?int, complete?: bool}  $data
     */
    public function recordLotResult(Inspection $inspection, array $data, User $by): Inspection
    {
        $this->assertLegacyEntry($inspection);
        if ($inspection->usesChecklistCapture()) {
            throw new BusinessRuleException('Use checklist submission with confirmed coverage for this inspection.');
        }
        if ($inspection->status->isTerminal()) {
            throw new BusinessRuleException('Inspection is already finalised.');
        }
        if ($inspection->status === InspectionStatus::AwaitingReview) {
            throw new BusinessRuleException('Inspection is awaiting checker review and its evidence is locked.');
        }

        if ($inspection->inspection_mode !== InspectionMode::LotChecklist) {
            throw new BusinessRuleException('recordLotResult is only available for lot-checklist inspections.');
        }

        return DB::transaction(function () use ($inspection, $data, $by) {
            app(WorkOrderOutputService::class)->lockTrialSource($inspection);
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);
            $trialQuantity = $data['inspected_quantity'] ?? null;
            if ($lockedInspection->startup_trial_required && $trialQuantity !== null && ! is_int($trialQuantity)) {
                throw new BusinessRuleException('Trial inspected quantity must be a whole number.');
            }
            app(WorkOrderOutputService::class)->assertTrialEvidence($lockedInspection, is_int($trialQuantity) ? $trialQuantity : null, (bool) ($data['complete'] ?? false));
            if ($lockedInspection->startup_trial_required && (! $by->is_active || ! $by->hasPermission('quality.inspections.manage'))) {
                throw new ForbiddenActionException('Active authorized QC user required to record trial evidence.');
            }
            if ($lockedInspection->status->isTerminal()) {
                throw new BusinessRuleException('Inspection is already finalised.');
            }
            if ($lockedInspection->status === InspectionStatus::AwaitingReview) {
                throw new BusinessRuleException('Inspection is awaiting checker review and its evidence is locked.');
            }

            // Fetch all measurement rows for validation.
            $allRows = InspectionMeasurement::query()
                ->where('inspection_id', $lockedInspection->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Convert checklist and measurements into the format recordMeasurements expects,
            // validating that each ID belongs to this inspection and is the correct type.
            $checklistMap = [];
            if (isset($data['checklist']) && is_array($data['checklist'])) {
                foreach ($data['checklist'] as $item) {
                    $id = $item['_id'] ?? (isset($item['id']) ? InspectionMeasurement::tryDecodeHash((string) $item['id']) : null);
                    if (! $id) {
                        throw new BusinessRuleException('Invalid or missing checklist measurement ID.');
                    }

                    $row = $allRows->get((int) $id);
                    if (! $row) {
                        throw new BusinessRuleException('Checklist measurement ID does not belong to this inspection.');
                    }

                    // Checklist rows must have no tolerance.
                    if ($row->tolerance_min !== null || $row->tolerance_max !== null) {
                        throw new BusinessRuleException('Checklist item must not have tolerance bounds.');
                    }

                    $checklistMap[(int) $id] = $item;
                }
            }

            $measurementMap = [];
            if (isset($data['measurements']) && is_array($data['measurements'])) {
                foreach ($data['measurements'] as $item) {
                    $id = $item['_id'] ?? (isset($item['id']) ? InspectionMeasurement::tryDecodeHash((string) $item['id']) : null);
                    if (! $id) {
                        throw new BusinessRuleException('Invalid or missing measurement ID.');
                    }

                    $row = $allRows->get((int) $id);
                    if (! $row) {
                        throw new BusinessRuleException('Measurement ID does not belong to this inspection.');
                    }

                    // Piece rows must have a tolerance window — a one-sided one is
                    // a window, and the scaffold writes exactly that for a
                    // parameter carrying a single bound. Ask the model so the two
                    // cannot drift apart again.
                    if (! $row->hasTolerance()) {
                        throw new BusinessRuleException('Measurement must have tolerance bounds.');
                    }

                    $measurementMap[(int) $id] = $item;
                }
            }

            // Preserve measurement IDs as array keys; array_merge() reindexes
            // numeric keys and turns valid rows into foreign-row errors.
            $allMeasurementMap = $checklistMap + $measurementMap;

            // Use recordMeasurements to apply and auto-evaluate rows.
            if ($allMeasurementMap !== []) {
                $this->recordMeasurements($lockedInspection, $allMeasurementMap, $by);
                $lockedInspection = $lockedInspection->fresh();
            }

            // Set sample_defect_count if provided (only for lot_checklist).
            if (isset($data['sample_defect_count'])) {
                $defectCount = (int) $data['sample_defect_count'];
                if ($defectCount < 0) {
                    throw new BusinessRuleException('sample_defect_count cannot be negative.');
                }
                if ($defectCount > (int) $lockedInspection->sample_size) {
                    throw new BusinessRuleException(
                        "sample_defect_count ({$defectCount}) cannot exceed sample_size ({$lockedInspection->sample_size})."
                    );
                }
                if ($lockedInspection->sample_defect_count !== $defectCount) {
                    $this->recordResultAuthor($lockedInspection, $by);
                }
                $lockedInspection->forceFill(['sample_defect_count' => $defectCount])->save();
            }

            // The legacy evidence endpoint must confirm the same sample coverage.
            if ($data['complete'] ?? false) {
                if (! ($data['coverage_confirmed'] ?? false) || $lockedInspection->sample_defect_count === null) {
                    throw new BusinessRuleException('Confirm all required checks on the planned sample before completing.');
                }
                $lockedInspection->forceFill(['inspected_quantity' => $lockedInspection->sample_size,
                    'defects_removed_confirmed' => (bool) ($data['defects_removed_confirmed'] ?? false)])->save();

                return $this->complete($lockedInspection->fresh(), $by);
            }

            return $this->show($lockedInspection->fresh());
        });
    }

    private function assertResultVersion(Inspection $inspection, ?int $expected): void
    {
        if ($expected === null || $expected !== (int) $inspection->result_version) {
            throw ValidationException::withMessages([
                'expected_result_version' => 'This inspection changed. Reload before submitting.',
            ]);
        }
    }

    /** Follow the source-first lock order used by warehouse and production writers. */
    private function lockInspectionSource(Inspection $inspection): void
    {
        app(WorkOrderOutputService::class)->lockTrialSource($inspection);
        if ($inspection->entity_type === InspectionEntityType::Grn) {
            GoodsReceiptNote::query()->lockForUpdate()->findOrFail($inspection->entity_id);
            if ($inspection->grn_item_id) {
                GrnItem::query()->lockForUpdate()->findOrFail($inspection->grn_item_id);
            }
        } elseif ($inspection->entity_type === InspectionEntityType::WorkOrder) {
            WorkOrder::query()->lockForUpdate()->findOrFail($inspection->entity_id);
            if ($inspection->wo_operation_id) {
                WoOperation::query()->lockForUpdate()->findOrFail($inspection->wo_operation_id);
            }
            if ($inspection->work_order_output_id) {
                WorkOrderOutput::query()->lockForUpdate()->findOrFail($inspection->work_order_output_id);
            }
        } elseif ($inspection->entity_type === InspectionEntityType::ReturnRequest) {
            ReturnRequest::query()->lockForUpdate()->findOrFail($inspection->entity_id);
        }
    }

    private function receivedReturnQuantity(ReturnRequest $return, int $productId): string
    {
        $quantity = '0';
        foreach ($return->items()->where('product_id', $productId)->get() as $line) {
            $received = $line->receipt_recorded || bccomp((string) $line->returned_quantity, '0', 3) > 0
                ? (string) $line->returned_quantity : (string) $line->quantity;
            $quantity = bcadd($quantity, $received, 3);
        }

        return $quantity;
    }

    private function assertChecklistSourceUnchanged(Inspection $inspection): void
    {
        if ($inspection->grn_item_id) {
            $line = GrnItem::query()->findOrFail($inspection->grn_item_id);
            $line->loadMissing(['item', 'grn']);
            if (! in_array($line->grn->status->value, ['pending_qc', 'partial_accepted'], true)
                || $line->grn->remainder_rejected_at !== null
                || bccomp((string) $line->quantity_accepted, '0', 3) > 0
                || (int) $line->goods_receipt_note_id !== (int) $inspection->entity_id
                || bccomp((string) $line->quantity_received, (string) $inspection->source_quantity, 3) !== 0
                || (int) $line->item_id !== (int) $inspection->item_id
                || strcasecmp((string) $line->item->unit_of_measure, (string) $inspection->source_uom_code) !== 0) {
                throw new BusinessRuleException('The receipt quantity changed. Reconcile the source before inspecting.');
            }
        }
        if ($inspection->work_order_output_id) {
            $output = WorkOrderOutput::query()->with('workOrder')->findOrFail($inspection->work_order_output_id);
            if (in_array($output->workOrder?->status?->value, ['closed', 'cancelled'], true)
                || (int) $output->work_order_id !== (int) $inspection->entity_id
                || (int) $output->workOrder?->product_id !== (int) $inspection->product_id
                || bccomp((string) ($inspection->stage === InspectionStage::InProcess && $output->production_started_at ? $output->physical_count : $output->good_count), (string) ($inspection->source_quantity ?? $inspection->batch_quantity), 3) !== 0
                || (int) ($inspection->stage === InspectionStage::InProcess && $output->production_started_at ? $output->physical_count : $output->good_count) !== (int) $inspection->batch_quantity) {
                throw new BusinessRuleException('The source output changed or was cancelled. Reconcile it before inspecting.');
            }
        }
        if ($inspection->entity_type === InspectionEntityType::WorkOrder && ! $inspection->work_order_output_id) {
            $workOrder = WorkOrder::query()->findOrFail($inspection->entity_id);
            $operation = $inspection->wo_operation_id ? WoOperation::query()->findOrFail($inspection->wo_operation_id) : null;
            $quantity = $operation ? (string) $operation->qty_completed : (string) $workOrder->quantity_target;
            if (in_array($workOrder->status->value, ['closed', 'cancelled'], true)
                || (int) $workOrder->product_id !== (int) $inspection->product_id
                || ($operation && (int) $operation->work_order_id !== (int) $workOrder->id)
                || bccomp($quantity, (string) $inspection->source_quantity, 4) !== 0) {
                throw new BusinessRuleException('Source work order changed or is closed. Reconcile it before inspecting.');
            }
        }
        if ($inspection->entity_type === InspectionEntityType::ReturnRequest) {
            $return = ReturnRequest::query()->findOrFail($inspection->entity_id);
            if (! in_array($return->status->value, ['received', 'inspected'], true) || $return->type->value !== $inspection->stage->value) {
                throw new BusinessRuleException('Return is no longer eligible for inspection.');
            }
            if ($inspection->return_request_item_id) {
                $line = ReturnRequestItem::query()->with(['item', 'product'])->findOrFail($inspection->return_request_item_id);
                if ((int) $line->return_request_id !== (int) $return->id || (int) $line->item_id !== (int) $inspection->item_id || (int) $line->product_id !== (int) $inspection->product_id) {
                    throw new BusinessRuleException('Return item changed. Reconcile the source before inspecting.');
                }
                $quantity = $line->receipt_recorded || bccomp((string) $line->returned_quantity, '0', 3) > 0
                    ? (string) $line->returned_quantity : (string) $line->quantity;
                $product = $line->product ?? $line->item;
            } else {
                $quantity = $this->receivedReturnQuantity($return, (int) $inspection->product_id);
                $product = Product::query()->findOrFail($inspection->product_id);
            }
            if (bccomp($quantity, (string) $inspection->source_quantity, 3) !== 0
                || strcasecmp((string) $product->unit_of_measure, (string) $inspection->source_uom_code) !== 0) {
                throw new BusinessRuleException('Return quantity changed. Reconcile the source before inspecting.');
            }
        }
    }

    private function pinCountedCapture(Inspection $inspection): Inspection
    {
        if (! config('inspection.counted_capture_enabled')) {
            return $inspection;
        }
        $rows = $inspection->measurements()->get();
        if ($rows->isEmpty() || $rows->contains(fn ($row) => trim((string) $row->acceptance_criterion) === '')) {
            throw new BusinessRuleException('QC must publish a requirement for every inspection check.');
        }
        $full = in_array($inspection->stage, [InspectionStage::Outgoing, InspectionStage::CustomerReturn, InspectionStage::SupplierReturn], true);
        $inspection->forceFill(['counted_capture' => [
            'version' => 1, 'result_policy' => 'zero_defects',
            'required_quantity' => $full ? $inspection->batch_quantity : $inspection->sample_size,
            'dimensional_quantity' => (int) $rows->filter(fn ($row) => $row->requiresSizeReading())->max('sample_index'),
        ]])->save();

        return $inspection;
    }

    private function assertLegacyEntry(Inspection $inspection): void
    {
        if ($inspection->counted_capture) {
            throw new BusinessRuleException('Submit defective quantity and required size readings together for this inspection.');
        }
    }

    private function assertCountedEvidence(Inspection $inspection): void
    {
        $required = (int) ($inspection->counted_capture['required_quantity'] ?? 0);
        $rows = $inspection->measurements()->get();
        if (($inspection->counted_capture['version'] ?? null) !== 1
            || ($inspection->counted_capture['result_policy'] ?? null) !== 'zero_defects'
            || $required < 1 || $required > $inspection->batch_quantity
            || ($inspection->stage === InspectionStage::Outgoing && $required !== $inspection->batch_quantity)
            || ! $inspection->coverage_confirmed_at || $inspection->inspected_quantity !== $required
            || $inspection->sample_defect_count === null || $inspection->sample_defect_count < 0
            || $inspection->sample_defect_count > $required || $rows->isEmpty()
            || $rows->contains(fn ($row) => trim((string) $row->acceptance_criterion) === ''
                || ($row->requiresSizeReading() && $row->measured_value === null))) {
            throw new BusinessRuleException('Complete the prescribed coverage, defective quantity and required size readings before review.');
        }
    }

    /** Validate full physical coverage under the pinned numeric or confirmed-check method. */
    public function assertFullPieceEvidence(Inspection $inspection): array
    {
        $required = (int) ($inspection->counted_capture['required_quantity'] ?? 0);
        if (! $inspection->usesFullPieceCapture()
            || ($inspection->counted_capture['result_policy'] ?? null) !== 'zero_defects'
            || $required < 1 || $required !== (int) $inspection->batch_quantity
            || ! $inspection->coverage_confirmed_at || (int) $inspection->inspected_quantity !== $required
            || $inspection->sample_defect_count === null || $inspection->sample_defect_count < 0
            || $inspection->sample_defect_count > $required) {
            throw new BusinessRuleException('Confirm full physical coverage and the defective-piece count before submitting.');
        }

        $output = WorkOrderOutput::query()->with('workOrder')->findOrFail($inspection->work_order_output_id);
        $workOrder = $output->workOrder;
        $terminalSourceEvidence = $inspection->status->isTerminal();
        if (! $workOrder || ! $workOrder->usesFinishFirstQc()
            || $inspection->stage !== InspectionStage::Outgoing
            || $inspection->entity_type !== InspectionEntityType::WorkOrder
            || (int) $inspection->entity_id !== (int) $workOrder->id
            || (int) $inspection->product_id !== (int) $workOrder->product_id
            || $this->fullPieceOutputQuantity($output) !== $required
            || bccomp((string) $inspection->source_quantity, (string) $required, 3) !== 0
            || strcasecmp((string) $inspection->source_uom_code, (string) $workOrder->product->unit_of_measure) !== 0
            || ! in_array($workOrder->status->value, ['completed', 'closed'], true)
            || ($workOrder->status->value === 'closed' && ! $terminalSourceEvidence
                && ! $this->isApprovedClosedCorrectionSource($output, $workOrder))) {
            throw new BusinessRuleException('Final QC source or physical quantity changed. Reconcile the work-order output first.');
        }
        $workOrder->assertFinishFirstRoutingCompatible();
        $revision = $inspection->specRevision()->with('items')->first();
        if (! $revision || (int) $revision->inspection_spec_id !== (int) $inspection->inspection_spec_id) {
            throw new BusinessRuleException('QC must preserve the published inspection-specification revision.');
        }

        $rows = $inspection->measurements()->with('specItem')->get();
        $references = $revision->items->keyBy('id');
        if ($references->isEmpty() || $rows->count() !== $references->count()) {
            throw new BusinessRuleException('QC must pin every final inspection requirement to its published revision.');
        }
        foreach ($rows as $row) {
            $reference = $references->get((int) $row->inspection_spec_item_id);
            if (! $reference || (int) $row->specItem?->inspection_spec_revision_id !== (int) $revision->id
                || $row->parameter_name !== $reference->parameter_name
                || $row->acceptance_criterion !== ChecklistDefinition::criterion($reference->toArray())
                || $row->parameter_type?->value !== $reference->parameter_type?->value
                || $row->unit_of_measure !== $reference->unit_of_measure
                || $row->nominal_value !== $reference->nominal_value
                || $row->tolerance_min !== $reference->tolerance_min
                || $row->tolerance_max !== $reference->tolerance_max
                || (bool) $row->is_critical !== (bool) $reference->is_critical) {
                throw new BusinessRuleException('A final inspection requirement does not match its pinned specification revision.');
            }
        }

        $requirements = $rows->filter(
            static fn (InspectionMeasurement $row): bool => $row->requiresSizeReading(),
        )->keyBy('id');
        $readings = InspectionPieceReading::query()->where('inspection_id', $inspection->id)
            ->with('requirement')->get();
        if (! $inspection->usesConfirmedChecks() && $readings->count() !== $required * $requirements->count()) {
            throw new BusinessRuleException('Enter every required size reading for every physical piece.');
        }
        if ($inspection->usesConfirmedChecks() && $readings->isNotEmpty()) {
            throw new BusinessRuleException('Confirmed checks cannot contain numeric measurement evidence.');
        }
        $failedPieceNumbers = [];
        foreach ($readings as $reading) {
            $requirement = $requirements->get((int) $reading->requirement_id);
            if (! $requirement || (int) $reading->requirement?->inspection_id !== (int) $inspection->id
                || $reading->piece_number < 1 || $reading->piece_number > $required) {
                throw new BusinessRuleException('A size reading does not belong to this inspection and physical piece.');
            }
            $evaluated = clone $requirement;
            $evaluated->measured_value = $reading->measured_value;
            $pass = $evaluated->evaluate();
            if ($pass === null || $reading->is_pass !== $pass) {
                throw new BusinessRuleException('A saved size reading no longer matches its pinned tolerance.');
            }
            if (! $pass) {
                $failedPieceNumbers[$reading->piece_number] = true;
            }
        }

        $defects = InspectionDefectivePiece::query()->where('inspection_id', $inspection->id)
            ->with('requirement')->orderBy('piece_number')->get();
        if ($inspection->usesConfirmedChecks() && $defects->contains(fn ($piece) => $piece->requirement_id !== null
            && (! $piece->requirement || (int) $piece->requirement->inspection_id !== (int) $inspection->id))) {
            throw new BusinessRuleException('A defective piece references a check from another inspection.');
        }
        if ($defects->count() !== (int) $inspection->sample_defect_count
            || $defects->contains(fn (InspectionDefectivePiece $piece): bool => $piece->piece_number < 1
                || $piece->piece_number > $required || trim($piece->problem_description) === ''
                || mb_strlen(trim($piece->problem_description)) > 500)) {
            throw new BusinessRuleException('Describe each defective piece once before submitting.');
        }
        $defectNumbers = $defects->pluck('piece_number')->map(static fn ($number): int => (int) $number)->all();
        if (array_diff(array_keys($failedPieceNumbers), $defectNumbers) !== []) {
            throw new BusinessRuleException('Describe every piece with an out-of-tolerance size reading.');
        }

        return ['defects' => $defects, 'failed_piece_numbers' => array_keys($failedPieceNumbers),
            'critical_defect' => $inspection->usesConfirmedChecks() && $defects->contains(fn ($piece) => $piece->requirement_id === null || (bool) $piece->requirement?->is_critical)];
    }

    /** Called by the atomic work-order group submit after it confirms full coverage. */
    public function recordFullPieceOutcome(Inspection $inspection, User $by): Inspection
    {
        if (! $by->is_active || ! $by->hasPermission('quality.inspections.manage')) {
            throw new ForbiddenActionException('Active authorized QC user required.');
        }

        return DB::transaction(function () use ($inspection, $by): Inspection {
            $this->lockInspectionSource($inspection);
            $locked = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);
            if (! $locked->usesFullPieceCapture() || $locked->status->isTerminal()
                || $locked->status === InspectionStatus::AwaitingReview) {
                throw new BusinessRuleException('Inspection evidence is locked. Reload its current result.');
            }
            $evidence = $this->assertFullPieceEvidence($locked);
            $badRequirements = InspectionPieceReading::query()->where('inspection_id', $locked->id)
                ->where('is_pass', false)->with('requirement')->get();
            $defects = (int) $locked->sample_defect_count;
            $target = $defects > 0 || $badRequirements->isNotEmpty()
                ? InspectionStatus::Failed : InspectionStatus::Passed;
            $problem = $evidence['defects']->map(static fn (InspectionDefectivePiece $piece): string =>
                '#'.$piece->piece_number.': '.trim($piece->problem_description))->implode("\n");
            $locked->forceFill([
                'recorded_result' => $target->value,
                'problem_description' => $target === InspectionStatus::Failed ? $problem : null,
                'affected_quantity' => $target === InspectionStatus::Failed ? $locked->batch_quantity : null,
                'critical_defect' => $evidence['critical_defect'] || $badRequirements->contains(static fn (InspectionPieceReading $reading): bool =>
                    (bool) $reading->requirement?->is_critical),
                'result_version' => (int) $locked->result_version + 1,
                'started_at' => $locked->started_at ?? now(),
            ]);

            return $this->submitOutcome($locked, $target, $by, $defects);
        });
    }

    private function recordCountedBatch(Inspection $inspection, array $data, User $by): Inspection
    {
        if (! $by->is_active || ! $by->hasPermission('quality.inspections.manage')) {
            throw new ForbiddenActionException('Active authorized QC user required.');
        }

        return DB::transaction(function () use ($inspection, $data, $by): Inspection {
            $this->lockInspectionSource($inspection);
            $locked = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);
            if (! $locked->counted_capture || $locked->status->isTerminal() || $locked->status === InspectionStatus::AwaitingReview) {
                throw new BusinessRuleException('Inspection evidence is locked. Reload its current result.');
            }
            $this->assertResultVersion($locked, $data['expected_result_version'] ?? null);
            if (array_key_exists('result', $data) || array_key_exists('items', $data) || array_key_exists('critical_defect', $data)) {
                throw new BusinessRuleException('The system calculates this inspection result from the recorded evidence.');
            }
            $quantity = $data['inspected_quantity'] ?? null;
            $lot = $locked->sampling_basis === 'lot';
            if ($lot && (array_key_exists('sample_defect_count', $data) || ! is_bool($data['physical_defects_confirmed'] ?? null))) {
                throw new BusinessRuleException('Confirm whether the material lot has defects; do not enter a piece count.');
            }
            $defects = $lot ? ($data['physical_defects_confirmed'] ? 1 : 0) : ($data['sample_defect_count'] ?? null);
            $required = (int) $locked->counted_capture['required_quantity'];
            if (($locked->counted_capture['version'] ?? null) !== 1
                || ($locked->counted_capture['result_policy'] ?? null) !== 'zero_defects'
                || $required < 1 || $required > $locked->batch_quantity
                || ($locked->stage === InspectionStage::Outgoing && $required !== $locked->batch_quantity)) {
                throw new BusinessRuleException('Pinned inspection coverage is invalid. QC must reconcile the specification.');
            }
            $problem = trim((string) ($data['problem_description'] ?? ''));
            $otherProblem = $data['requirement_problem'] ?? false;
            $historicalHold = $locked->work_order_output_id && (int) $locked->workOrderOutput?->held_quantity > 0;
            if ($problem !== '' && $defects === 0 && ! $otherProblem && ! $historicalHold) {
                throw new BusinessRuleException('Confirm the requirement problem or correct the defective quantity.');
            }
            if (($data['coverage_confirmed'] ?? false) !== true || ! is_int($quantity) || $quantity !== $required
                || ! is_int($defects) || $defects < 0 || $defects > $quantity || ! is_bool($otherProblem)) {
                throw new BusinessRuleException('Confirm the prescribed checked quantity and count each defective piece once.');
            }
            if (($defects > 0 || $otherProblem) && ($problem === '' || mb_strlen($problem) > 2000)) {
                throw new BusinessRuleException('Describe what is wrong before submitting a failed inspection.');
            }
            if ($locked->isCountOnlyIncoming() && $defects > 0
                && (! ($data['defects_removed_confirmed'] ?? false) || $otherProblem)) {
                throw new BusinessRuleException('Segregate defective pieces before submitting; report other requirement failures separately.');
            }
            app(WorkOrderOutputService::class)->assertTrialEvidence($locked, $quantity, true);
            $this->assertChecklistSourceUnchanged($locked);
            $rows = $locked->measurements()->lockForUpdate()->get();
            if ($rows->isEmpty() || $rows->contains(fn ($row) => trim((string) $row->acceptance_criterion) === '')) {
                throw new BusinessRuleException('An approved inspection requirement is missing.');
            }
            $seen = [];
            foreach ($data['measurements'] ?? [] as $reading) {
                $id = (int) ($reading['id'] ?? 0);
                $row = $rows->firstWhere('id', $id);
                $value = $reading['measured_value'] ?? null;
                if (! $row || ! $row->requiresSizeReading() || isset($seen[$id])
                    || $value === null || ! preg_match('/^-?\d{1,8}(?:\.\d{1,4})?$/', (string) $value)
                    || array_key_exists('is_pass', $reading) || array_key_exists('tolerance_min', $reading) || array_key_exists('tolerance_max', $reading)) {
                    throw new BusinessRuleException('Record one actual reading for each required size check belonging to this inspection.');
                }
                $seen[$id] = true;
                $row->measured_value = (string) $value;
                $row->is_pass = $row->evaluate();
                $row->save();
            }
            if ($rows->contains(fn ($row) => $row->requiresSizeReading() && $row->measured_value === null)) {
                throw new BusinessRuleException('Enter all required size readings.');
            }
            $badSizes = $rows->filter(fn ($row) => $row->requiresSizeReading() && $row->evaluate() === false);
            if ($badSizes->isNotEmpty() && ($defects < ($lot ? 1 : $badSizes->pluck('sample_index')->unique()->count()) || $problem === '')) {
                throw new BusinessRuleException('Out-of-limit sizes require a defective quantity and problem description.');
            }
            if ($locked->calibration_record_id) {
                $calibration = CalibrationRecord::query()->findOrFail($locked->calibration_record_id);
                if (in_array($calibration->status, [CalibrationStatus::Overdue, CalibrationStatus::Retired], true)) {
                    throw new BusinessRuleException('Referenced inspection equipment is overdue or retired.');
                }
            }
            if ($historicalHold && $problem === '') {
                $problem = 'Production occurred before required QC release; independent process recovery is required.';
            }
            $target = ($defects > 0 || $otherProblem || $badSizes->isNotEmpty() || $historicalHold) ? InspectionStatus::Failed : InspectionStatus::Passed;
            $locked->forceFill(['inspected_quantity' => $quantity, 'sample_defect_count' => $defects,
                'coverage_confirmed_at' => now(), 'result_version' => $locked->result_version + 1,
                'critical_defect' => $badSizes->contains('is_critical', true),
                'defects_removed_confirmed' => $locked->isCountOnlyIncoming() && $defects > 0,
                'recorded_result' => $target->value, 'problem_description' => $target === InspectionStatus::Failed ? $problem : null,
                'affected_quantity' => $target === InspectionStatus::Failed ? $locked->batch_quantity : null,
                'started_at' => $locked->started_at ?? now()]);

            return $this->submitOutcome($locked, $target, $by, $defects);
        });
    }

    /** One complete request commits checklist evidence and its outcome together. */
    private function recordChecklistSubmission(Inspection $inspection, array $data, User $by, bool $draft = false): Inspection
    {
        return DB::transaction(function () use ($inspection, $data, $by, $draft): Inspection {
            $this->lockInspectionSource($inspection);
            $locked = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);
            app(WorkOrderOutputService::class)->assertTrialEvidence($locked);
            if ($locked->startup_trial_required && (! $by->is_active || ! $by->hasPermission('quality.inspections.manage'))) {
                throw new ForbiddenActionException('Active authorized QC user required to record trial evidence.');
            }
            if ($locked->status->isTerminal() || $locked->status === InspectionStatus::AwaitingReview) {
                throw new BusinessRuleException('Inspection evidence is locked. Reload its current result.');
            }
            $this->assertResultVersion($locked, $data['expected_result_version'] ?? null);
            foreach (['result', 'critical_defect', 'measured_value', 'nominal_value', 'tolerance_min', 'tolerance_max'] as $field) {
                if (array_key_exists($field, $data)) {
                    throw new BusinessRuleException('Checklist outcomes are derived from check decisions and coverage.');
                }
            }
            $rows = $locked->measurements()->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw new BusinessRuleException('Inspection has no approved checklist.');
            }
            $seen = [];
            foreach ($data['items'] ?? [] as $item) {
                $id = (int) ($item['id'] ?? 0);
                $row = $rows->firstWhere('id', $id);
                if (! $row || isset($seen[$id])) {
                    throw new BusinessRuleException('Record one decision per check belonging to this inspection.');
                }
                if (trim((string) $row->acceptance_criterion) === '') {
                    throw new BusinessRuleException('An approved acceptance criterion is missing.');
                }
                $result = $item['result'] ?? null;
                $problem = trim((string) ($item['problem_description'] ?? ''));
                if (! in_array($result, ['passed', 'failed'], true) || ($result === 'failed' && ($problem === '' || mb_strlen($problem) > 500))) {
                    throw new BusinessRuleException('Every failed check needs a problem description in 1–500 characters.');
                }
                foreach (['nominal_value', 'tolerance_min', 'tolerance_max', 'is_pass'] as $field) {
                    if (array_key_exists($field, $item)) {
                        throw new BusinessRuleException('Measurement fields are not accepted for a PASS/FAIL checklist.');
                    }
                }
                if ($row->requiresSizeReading()) {
                    $value = array_key_exists('measured_value', $item) ? $item['measured_value'] : $row->measured_value;
                    if ($value === null || ! preg_match('/^-?\d{1,8}(?:\.\d{1,4})?$/', (string) $value)) {
                        throw new BusinessRuleException('Enter the actual size for '.$row->parameter_name.' with up to 4 decimal places.');
                    }
                    $row->measured_value = (string) $value;
                    $row->is_pass = $row->evaluate();
                    if ($result === 'passed' && $row->is_pass !== true) {
                        throw new BusinessRuleException('PASS for '.$row->parameter_name.' contradicts its measured size.');
                    }
                } elseif (array_key_exists('measured_value', $item)) {
                    throw new BusinessRuleException('Only size checks with approved limits accept measured values.');
                }
                $seen[$id] = true;
                $row->forceFill(['item_result' => $result, 'item_problem_description' => $result === 'failed' ? $problem : null])->save();
            }
            $locked->result_version++;
            if ($draft) {
                $this->recordResultAuthor($locked, $by);
                $locked->forceFill(['status' => InspectionStatus::InProgress, 'started_at' => $locked->started_at ?? now()])->save();

                return $this->show($locked->fresh());
            }
            if (count($seen) !== $rows->count()) {
                throw new BusinessRuleException('Submit every checklist check, including hidden checks.');
            }
            if ($rows->contains(fn ($row) => $row->requiresSizeReading() && $row->measured_value === null)) {
                throw new BusinessRuleException('Record actual size for every check with approved size limits.');
            }
            $quantity = $data['inspected_quantity'] ?? (int) $locked->sample_size;
            if (($data['coverage_confirmed'] ?? false) !== true || ! is_int($quantity)
                || $quantity < $locked->sample_size || $quantity > $locked->batch_quantity) {
                throw new BusinessRuleException('Confirm actual coverage of the planned sample before submitting.');
            }
            if (array_key_exists('defects_removed_confirmed', $data) && ! is_bool($data['defects_removed_confirmed'])) {
                throw new BusinessRuleException('Confirm whether sampled defective pieces were segregated.');
            }
            $this->assertChecklistSourceUnchanged($locked);
            $failedChecks = $rows->where('item_result', 'failed');
            $batchProblem = trim((string) ($data['problem_description'] ?? ''));
            $reportedPhysical = $data['physical_defects_confirmed'] ?? null;
            if ($rows->contains(fn ($row) => $row->requiresSizeReading() && $row->evaluate() === false) && $reportedPhysical !== true) {
                throw new BusinessRuleException('An out-of-limit size is a physical defect. Confirm affected pieces or the material lot.');
            }
            if (($failedChecks->isNotEmpty() || $batchProblem !== '') && ! is_bool($reportedPhysical)) {
                throw new BusinessRuleException('Confirm whether physically defective pieces or lots were found.');
            }
            $defects = $locked->sampling_basis === 'lot'
                ? ($reportedPhysical === true ? 1 : 0)
                : ($data['sample_defect_count'] ?? 0);
            if ($locked->sampling_basis === 'lot' && array_key_exists('sample_defect_count', $data)) {
                throw new BusinessRuleException('Lot inspections derive affected lots; do not enter a piece count.');
            }
            if ($batchProblem !== '' && $reportedPhysical !== true) {
                throw new BusinessRuleException('A batch-only problem requires confirmation of physical defects.');
            }
            if (! is_int($defects) || $defects < 0 || $defects > $quantity
                || ($reportedPhysical === true && $defects < 1) || ($reportedPhysical !== true && $defects !== 0)) {
                throw new BusinessRuleException('Enter a valid unique defective-piece count; count each piece once.');
            }
            if ($locked->isCountOnlyIncoming() && (! array_key_exists('sample_defect_count', $data)
                || $rows->count() !== 1 || $rows->first()->parameter_name !== 'Full piece count'
                || $failedChecks->isNotEmpty())) {
                throw new BusinessRuleException('Confirm the full piece count and enter the defective-piece quantity.');
            }
            if ($failedChecks->isEmpty() && $defects > 0 && ($batchProblem === '' || mb_strlen($batchProblem) > 2000)) {
                throw new BusinessRuleException('Describe the physical problem found outside the named checks.');
            }
            if ($locked->calibration_record_id) {
                $record = CalibrationRecord::query()->findOrFail($locked->calibration_record_id);
                if (in_array($record->status, [CalibrationStatus::Overdue, CalibrationStatus::Retired], true)) {
                    throw new BusinessRuleException('Referenced inspection equipment is overdue or retired.');
                }
            }
            $critical = $failedChecks->contains('is_critical', true);
            $passed = $failedChecks->isEmpty() && ! $critical && $defects <= (int) $locked->accept_count;
            $defectsRemoved = ($data['defects_removed_confirmed'] ?? false) === true;
            if (($passed || $locked->isCountOnlyIncoming()) && $defects > 0 && ! $defectsRemoved) {
                throw new BusinessRuleException('Segregate known defective pieces before submitting the inspection.');
            }
            $target = $passed ? InspectionStatus::Passed : InspectionStatus::Failed;
            $problem = $failedChecks->map(fn ($row) => $row->parameter_name.': '.$row->item_problem_description)
                ->prepend($batchProblem)->filter()->implode("\n");
            $locked->forceFill(['inspected_quantity' => $quantity, 'sample_defect_count' => $defects,
                'critical_defect' => $critical, 'recorded_result' => $target->value,
                'problem_description' => $target === InspectionStatus::Passed
                    ? ($defects > 0 ? $problem : null)
                    : $problem,
                'affected_quantity' => $target === InspectionStatus::Failed ? $locked->batch_quantity : null,
                'defects_removed_confirmed' => $defectsRemoved, 'started_at' => $locked->started_at ?? now()]);

            return $this->submitOutcome($locked, $target, $by, $defects);
        });
    }

    /** Record sample coverage once; the pinned acceptance plan derives the batch verdict. */
    public function recordBatchResult(Inspection $inspection, array $data, User $by): Inspection
    {
        if ($inspection->counted_capture) {
            return $this->recordCountedBatch($inspection, $data, $by);
        }
        if ($inspection->usesChecklistCapture()) {
            return $this->recordChecklistSubmission($inspection, $data, $by);
        }

        return DB::transaction(function () use ($inspection, $data, $by) {
            app(WorkOrderOutputService::class)->lockTrialSource($inspection);
            $locked = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);
            app(WorkOrderOutputService::class)->assertTrialEvidence($locked);
            if ($locked->startup_trial_required && (! $by->is_active || ! $by->hasPermission('quality.inspections.manage'))) {
                throw new ForbiddenActionException('Active authorized QC user required to record trial evidence.');
            }
            if ($locked->status->isTerminal() || $locked->status === InspectionStatus::AwaitingReview) {
                throw new BusinessRuleException('Inspection is already finalised.');
            }
            $quantity = $data['inspected_quantity'] ?? null;
            $defects = $data['sample_defect_count'] ?? null;
            $result = $data['result'] ?? null;
            $problem = trim((string) ($data['problem_description'] ?? ''));
            $critical = $data['critical_defect'] ?? false;
            if (! ($data['coverage_confirmed'] ?? false)
                || ! is_int($quantity) || $quantity < max(1, $locked->sample_size) || $quantity > $locked->batch_quantity
                || ! is_int($defects) || $defects < 0 || $defects > $quantity
                || ! is_bool($critical) || ! in_array($result, ['passed', 'failed'], true)) {
                throw new BusinessRuleException('Confirm all required checks on the planned sample and enter a valid unique defective-piece count.');
            }
            if (($result === 'passed' && ($defects !== 0 || $critical))
                || ($result === 'failed' && ($problem === '' || mb_strlen($problem) > 2000))) {
                throw new BusinessRuleException('PASS requires zero observed defects. FAIL requires a problem description.');
            }
            $rows = $locked->measurements()->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw new BusinessRuleException('Inspection has no approved requirements.');
            }
            $groups = $rows->groupBy(static fn (InspectionMeasurement $row): string => $row->checklistKey());
            if ($groups->contains(static fn ($group): bool => $group->first()->item_result === null)) {
                throw new BusinessRuleException('Record every inspection requirement before submitting a batch result.');
            }
            $failedChecklist = $groups->filter(static fn ($group): bool => $group->first()->item_result === 'failed');
            if ($result === 'failed' && $defects < 1 && $failedChecklist->isEmpty() && ! $critical) {
                throw new BusinessRuleException('A failed batch needs a failed requirement, a defective piece, or a critical defect.');
            }
            $savedFailures = $rows->contains(static fn (InspectionMeasurement $row): bool => $row->item_result === 'failed' || $row->is_pass === false || $row->evaluate() === false);
            if ($result === 'passed' && $savedFailures) {
                throw new BusinessRuleException('PASS contradicts saved inspection evidence.');
            }
            $evidence = LotDefectCounter::for($locked, $rows);
            $critical = $critical || $evidence['criticalFail'];
            if ($defects < $evidence['defects']) {
                throw new BusinessRuleException('The reported count and critical severity must include saved inspection failures.');
            }
            $passed = $failedChecklist->isEmpty() && ! $critical && $defects <= (int) $locked->accept_count;
            if ($passed && $defects > 0 && ! ($data['defects_removed_confirmed'] ?? false)) {
                throw new BusinessRuleException('Remove and segregate the defective pieces before accepting the remaining batch.');
            }
            if ($locked->calibration_record_id) {
                $record = CalibrationRecord::query()->find($locked->calibration_record_id);
                if ($record && in_array($record->status, [CalibrationStatus::Overdue, CalibrationStatus::Retired], true)) {
                    throw new BusinessRuleException('Cannot complete with overdue or retired measuring equipment.');
                }
            }
            $target = $passed ? InspectionStatus::Passed : InspectionStatus::Failed;
            $checkProblems = $failedChecklist
                ->map(static fn ($group): string => $group->first()->parameter_name.': '.$group->first()->item_problem_description)
                ->implode("\n");
            $failureDescription = $defects > 0 && $checkProblems !== ''
                ? trim($problem."\n".$checkProblems)
                : ($checkProblems !== '' ? $checkProblems : $problem);
            $locked->forceFill([
                'inspected_quantity' => $quantity,
                'sample_defect_count' => $defects,
                'critical_defect' => $critical,
                'defects_removed_confirmed' => (bool) ($data['defects_removed_confirmed'] ?? false),
                'recorded_result' => $target->value,
                'problem_description' => $passed
                    ? ($defects > 0 ? $problem : null)
                    : $failureDescription,
                'affected_quantity' => $passed ? null : $locked->batch_quantity,
                'started_at' => $locked->started_at ?? now(),
            ]);

            return $this->submitOutcome($locked, $target, $by, $defects);
        });
    }

    /** Save one explicit decision per parameter without fabricating measurement evidence. */
    public function recordItemResults(Inspection $inspection, array $data, User $by): Inspection
    {
        $this->assertLegacyEntry($inspection);
        if ($inspection->usesChecklistCapture()) {
            if ($data['submit'] ?? false) {
                throw new BusinessRuleException('Submit the complete checklist with confirmed coverage.');
            }

            return $this->recordChecklistSubmission($inspection, $data, $by, true);
        }

        return DB::transaction(function () use ($inspection, $data, $by) {
            app(WorkOrderOutputService::class)->lockTrialSource($inspection);
            $locked = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);
            app(WorkOrderOutputService::class)->assertTrialEvidence($locked);
            if ($locked->startup_trial_required && (! $by->is_active || ! $by->hasPermission('quality.inspections.manage'))) {
                throw new ForbiddenActionException('Active authorized QC user required to record trial evidence.');
            }
            if ($locked->inspection_mode === InspectionMode::LotChecklist && ($data['submit'] ?? false)) {
                throw new BusinessRuleException(
                    'Lot-checklist inspections require sample coverage and a defective-piece count before submission.'
                );
            }
            if ($locked->status->isTerminal() || $locked->status === InspectionStatus::AwaitingReview) {
                throw new BusinessRuleException('Inspection is already finalised.');
            }
            $rows = InspectionMeasurement::query()->where('inspection_id', $locked->id)
                ->orderBy('sample_index')->orderBy('id')->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw new BusinessRuleException('Inspection has no checklist items.');
            }
            $groups = $rows->groupBy(static fn (InspectionMeasurement $row): string => $row->checklistKey());
            $seen = [];
            $updates = [];
            foreach ($data['items'] ?? [] as $item) {
                $row = $rows->firstWhere('id', (int) ($item['id'] ?? 0));
                if (! $row) {
                    throw new BusinessRuleException('Checklist item does not belong to this inspection.');
                }
                $key = $row->checklistKey();
                if (isset($seen[$key])) {
                    throw new BusinessRuleException('Record only one decision for each checklist item.');
                }
                $seen[$key] = true;
                $result = $item['result'] ?? null;
                if (! in_array($result, ['passed', 'failed'], true)) {
                    throw new BusinessRuleException('Every item result must be passed or failed.');
                }
                $problem = trim((string) ($item['problem_description'] ?? ''));
                if ($result === 'failed' && ($problem === '' || mb_strlen($problem) > 500)) {
                    throw new BusinessRuleException('Describe each failed item in 1–500 characters.');
                }
                $group = $groups->get($key);
                if ($result === 'passed' && $group->contains(static fn (InspectionMeasurement $reading): bool => $reading->is_pass === false || $reading->evaluate() === false)) {
                    throw new BusinessRuleException('PASS for '.$row->parameter_name.' contradicts saved measurement evidence.');
                }
                $updates[$key] = [
                    'row' => $group->first(),
                    'result' => $result,
                    'problem' => $result === 'failed' ? $problem : null,
                ];
            }

            foreach ($updates as $update) {
                $update['row']->forceFill([
                    'item_result' => $update['result'],
                    'item_problem_description' => $update['problem'],
                ])->save();
            }

            if (! ($data['submit'] ?? false)) {
                $this->recordResultAuthor($locked, $by);
                $locked->forceFill(['status' => InspectionStatus::InProgress, 'started_at' => $locked->started_at ?? now()])->save();

                return $this->show($locked->fresh());
            }

            if ($groups->contains(static fn ($group): bool => $group->first()->item_result === null)) {
                throw new BusinessRuleException('Every inspection requirement needs a PASS or FAIL decision before submission.');
            }
            foreach ($groups as $group) {
                if ($group->first()->item_result === 'passed'
                    && $group->contains(static fn (InspectionMeasurement $reading): bool => $reading->is_pass === false || $reading->evaluate() === false)) {
                    throw new BusinessRuleException('PASS for '.$group->first()->parameter_name.' contradicts saved measurement evidence.');
                }
            }

            $evidence = LotDefectCounter::for($locked, $rows);
            $failed = $groups->filter(static fn ($group): bool => $group->first()->item_result === 'failed');
            $target = $failed->isNotEmpty() || $evidence['criticalFail'] || $evidence['defects'] > (int) $locked->accept_count
                ? InspectionStatus::Failed
                : InspectionStatus::Passed;
            $problem = $failed->map(static fn ($group): string => $group->first()->parameter_name.': '.$group->first()->item_problem_description)
                ->merge($rows->filter(static fn (InspectionMeasurement $row): bool => $row->is_pass === false || $row->evaluate() === false)
                    ->map(static fn (InspectionMeasurement $row): string => $row->parameter_name.' failed'.($row->measured_value !== null ? ' at '.$row->measured_value.' '.$row->unit_of_measure : '')))
                ->filter()
                ->implode("\n");
            if ($target === InspectionStatus::Failed && trim($problem) === '') {
                $problem = trim((string) $locked->problem_description) ?: 'Inspection acceptance criteria were not met.';
            }
            $locked->forceFill([
                'inspected_quantity' => $locked->startup_trial_required ? (int) $locked->sample_size : $locked->inspected_quantity,
                'recorded_result' => $target->value,
                'problem_description' => $target === InspectionStatus::Failed ? $problem : null,
            ])->save();
            $this->recordResultAuthor($locked, $by);

            return $this->submitOutcome($locked, $target, $by, $evidence['defects']);
        });
    }

    /**
     * Finalise the inspection. Computes pass/fail from current measurements:
     *  - any critical fail → Failed
     *  - defect_count > accept_count → Failed
     *  - any unresolved (is_pass=null) row → block completion
     *  - else Passed
     */
    public function complete(Inspection $inspection, User $by): Inspection
    {
        $this->assertLegacyEntry($inspection);
        if ($inspection->usesChecklistCapture()) {
            throw new BusinessRuleException('Use checklist submission with confirmed coverage for this inspection.');
        }
        if ($inspection->status->isTerminal() || $inspection->status === InspectionStatus::AwaitingReview) {
            throw new BusinessRuleException('Inspection is already finalised.');
        }

        return DB::transaction(function () use ($inspection, $by) {
            app(WorkOrderOutputService::class)->lockTrialSource($inspection);
            // The caller may hold a stale in-progress model after another
            // worker has already completed the inspection. Lock and recheck
            // the authoritative row so terminal outcomes are immutable.
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);
            app(WorkOrderOutputService::class)->assertTrialEvidence($lockedInspection);
            if ($lockedInspection->startup_trial_required && (! $by->is_active || ! $by->hasPermission('quality.inspections.manage'))) {
                throw new ForbiddenActionException('Active authorized QC user required to record trial evidence.');
            }
            if ($lockedInspection->status->isTerminal() || $lockedInspection->status === InspectionStatus::AwaitingReview) {
                throw new BusinessRuleException('Inspection is already finalised.');
            }

            $rows = InspectionMeasurement::query()
                ->where('inspection_id', $lockedInspection->id)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                throw new BusinessRuleException('Cannot complete: inspection has no measurement rows.');
            }

            if ($rows->contains(static fn (InspectionMeasurement $row): bool => $row->item_result !== null)) {
                throw new BusinessRuleException('Submit the batch decision with sample coverage and unique defective pieces. Checklist decisions alone cannot release a batch.');
            }

            // A row nothing has answered is neither a pass nor a fail. On a
            // lot-checklist inspection this includes every piece row of a
            // dimension the inspector never ticked: the tick is what records the
            // answer (as `is_pass`), so silence here is an unanswered dimension,
            // not a conforming one.
            $unresolved = $rows->whereNull('is_pass')->count();
            if ($unresolved > 0) {
                throw new BusinessRuleException("Cannot complete: {$unresolved} measurement(s) have no pass/fail recorded.");
            }

            // A lot-checklist verdict is meaningless without the reported count,
            // so the precondition is checked before anything derives from it.
            if ($lockedInspection->inspection_mode === InspectionMode::LotChecklist
                && ($lockedInspection->sample_defect_count === null || $lockedInspection->inspected_quantity === null)) {
                throw new BusinessRuleException(
                    'Confirm sample coverage and enter the number of defective pieces found (0 if none).'
                );
            }

            // Per-unit inspections are enumerated, so the declared sample must
            // actually have been measured. Lot-checklist inspections count their
            // sample instead, and their measured pieces are deliberately fewer.
            if ($lockedInspection->inspection_mode !== InspectionMode::LotChecklist) {
                $sampledUnits = $rows->pluck('sample_index')->unique()->count();
                $declaredSample = (int) $lockedInspection->sample_size;
                if ($declaredSample > 0 && $sampledUnits < $declaredSample) {
                    throw new BusinessRuleException(
                        "Cannot complete: inspection declares a sample of {$declaredSample} unit(s) but only {$sampledUnits} were measured.",
                    );
                }
            }

            ['defects' => $defects, 'criticalFail' => $criticalFail] = LotDefectCounter::for($lockedInspection, $rows);
            $lockedInspection->forceFill(['inspected_quantity' => $lockedInspection->inspected_quantity ?? $lockedInspection->sample_size,
                'sample_defect_count' => $defects]);

            if ($lockedInspection->calibration_record_id) {
                $record = CalibrationRecord::query()->find((int) $lockedInspection->calibration_record_id);
                if ($record && in_array($record->status, [CalibrationStatus::Overdue, CalibrationStatus::Retired], true)) {
                    throw new BusinessRuleException(
                        "Cannot complete: measuring equipment {$record->equipment_code} is {$record->status->value}."
                    );
                }
            }

            if ($defects === 0 && $rows->contains(static fn (InspectionMeasurement $row): bool => $row->is_pass === false)) {
                throw new BusinessRuleException('Saved failures require a positive unique defective-piece count.');
            }
            if (! $criticalFail && $defects > 0 && $defects <= (int) $lockedInspection->accept_count
                && ! $lockedInspection->defects_removed_confirmed) {
                throw new BusinessRuleException('Segregate known defective pieces before accepting the batch.');
            }
            $accept = (int) $lockedInspection->accept_count;
            $passed = ! $criticalFail && $defects <= $accept;
            $targetStatus = $passed ? InspectionStatus::Passed : InspectionStatus::Failed;

            return $this->submitOutcome($lockedInspection, $targetStatus, $by, $defects);
        });
    }

    /** Both evidence-based completion and quick decisions keep the same release gate. */
    private function submitOutcome(Inspection $inspection, InspectionStatus $target, User $by, int $defects): Inspection
    {
        app(WorkOrderOutputService::class)->assertTrialEvidence($inspection, null, true);
        if ($target === InspectionStatus::Passed) {
            $this->assertCriticalMeasurementsRecorded($inspection);
        }

        if ($inspection->status === InspectionStatus::Draft) {
            $inspection->setAttribute('status', InspectionStatus::InProgress);
        }
        $this->recordResultAuthor($inspection, $by);
        if ($inspection->requiresMakerChecker()) {
            $this->states->assertAllowed($inspection, InspectionStatus::AwaitingReview);
            $inspection->forceFill([
                'status' => InspectionStatus::AwaitingReview->value,
                'proposed_result' => $target->value,
                'defect_count' => $defects,
                'accepted_quantity' => 0,
                'completed_at' => null,
                'inspector_id' => $inspection->inspector_id ?? $by->id,
            ])->save();
            $this->notifyReviewers($inspection, $by);

            return $this->show($inspection->fresh());
        }
        $this->states->assertAllowed($inspection, $target);

        return $this->finalizeTerminal($inspection, $target, $by, $defects);
    }

    /**
     * Finalize a high-risk result after a different user has checked it.
     */
    public function review(Inspection $inspection, string $decision, ?string $remarks, User $by, ?int $expectedVersion = null, bool $fullPieceGroup = false): Inspection
    {
        $targetStatus = InspectionStatus::tryFrom($decision);
        if (! in_array($targetStatus, [InspectionStatus::Passed, InspectionStatus::Failed], true)) {
            throw new BusinessRuleException('Inspection review decision must be passed or failed.');
        }

        return DB::transaction(function () use ($inspection, $targetStatus, $remarks, $by, $expectedVersion, $fullPieceGroup) {
            app(WorkOrderOutputService::class)->lockTrialSource($inspection);
            if ($inspection->usesChecklistCapture() || $inspection->counted_capture) {
                $this->lockInspectionSource($inspection);
            }
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);
            app(WorkOrderOutputService::class)->assertTrialEvidence($lockedInspection, null, true);
            if ($lockedInspection->startup_trial_required && (! $by->is_active || ! $by->hasPermission('quality.inspections.review'))) {
                throw new ForbiddenActionException('Active authorized checker required to approve trial QC.');
            }

            if ($lockedInspection->usesFullPieceCapture()) {
                if (! $fullPieceGroup) {
                    throw new BusinessRuleException('Review this inspection through its work-order final inspection.');
                }
                if (! $by->is_active || ! $by->hasPermission('quality.inspections.review')) {
                    throw new ForbiddenActionException('Active authorized QC reviewer required.');
                }
                $this->assertResultVersion($lockedInspection, $expectedVersion);
                $evidence = $this->assertFullPieceEvidence($lockedInspection);
                $failed = $evidence['defects']->isNotEmpty() || $evidence['failed_piece_numbers'] !== [];
                if ($lockedInspection->usesConfirmedChecks() && (bool) $lockedInspection->critical_defect !== $evidence['critical_defect']) {
                    throw new BusinessRuleException('Critical defect evidence changed. Reload the inspection.');
                }
                $evidenceResult = $failed ? InspectionStatus::Failed : InspectionStatus::Passed;
                $proposedValue = $lockedInspection->proposed_result instanceof \BackedEnum
                    ? $lockedInspection->proposed_result->value : (string) $lockedInspection->proposed_result;
                if ($proposedValue !== $evidenceResult->value || $targetStatus !== $evidenceResult) {
                    throw new BusinessRuleException('Review must confirm the result calculated from the saved full-piece evidence.');
                }
                $lockedInspection->result_version++;
            } elseif ($lockedInspection->counted_capture) {
                if (! $by->is_active || ! $by->hasPermission('quality.inspections.review')) {
                    throw new ForbiddenActionException('Active authorized QC reviewer required.');
                }
                $this->assertResultVersion($lockedInspection, $expectedVersion);
                $this->assertChecklistSourceUnchanged($lockedInspection);
                $this->assertCountedEvidence($lockedInspection);
                $lockedInspection->result_version++;
            } elseif ($lockedInspection->usesChecklistCapture() || $lockedInspection->counted_capture) {
                $this->assertResultVersion($lockedInspection, $expectedVersion);
                $this->assertChecklistSourceUnchanged($lockedInspection);
                $checks = $lockedInspection->measurements()->lockForUpdate()->get();
                if ($checks->isEmpty() || $checks->contains(fn ($row) => ! in_array($row->item_result, ['passed', 'failed'], true)
                    || trim((string) $row->acceptance_criterion) === ''
                    || ($row->requiresSizeReading() && $row->measured_value === null)
                    || ($row->item_result === 'failed' && trim((string) $row->item_problem_description) === ''))
                    || $lockedInspection->sample_defect_count === null || $lockedInspection->inspected_quantity === null
                    || $lockedInspection->inspected_quantity < $lockedInspection->sample_size
                    || $lockedInspection->inspected_quantity > $lockedInspection->batch_quantity) {
                    throw new BusinessRuleException('Review requires complete checklist evidence and confirmed coverage.');
                }
                $lockedInspection->result_version++;
            }
            if ($lockedInspection->status !== InspectionStatus::AwaitingReview) {
                throw new BusinessRuleException('Only inspections awaiting review can be checked.');
            }
            if (! $lockedInspection->inspector_id) {
                throw new BusinessRuleException('Inspection has no maker; the result cannot be checked.');
            }
            if ((int) $lockedInspection->inspector_id === (int) $by->id
                || $lockedInspection->hasResultAuthor($by)) {
                throw new ForbiddenActionException('You cannot review an inspection you performed.');
            }

            $remarks = $remarks !== null ? trim($remarks) : null;
            $proposed = $lockedInspection->proposed_result instanceof InspectionOutcome
                ? $lockedInspection->proposed_result
                : InspectionOutcome::tryFrom((string) $lockedInspection->proposed_result);
            if (! $proposed) {
                throw new BusinessRuleException('Inspection has no valid proposed result; review is blocked.');
            }
            if ($targetStatus === InspectionStatus::Passed && $proposed === InspectionOutcome::Failed) {
                throw new BusinessRuleException('A failed inspection cannot be overridden to PASS. Record sorting or rework evidence through the linked NCR.');
            }
            if ($targetStatus === InspectionStatus::Passed) {
                if ($lockedInspection->inspection_mode === InspectionMode::LotChecklist
                    && ($lockedInspection->sample_defect_count === null || $lockedInspection->inspected_quantity === null)) {
                    throw new BusinessRuleException(
                        'Lot-checklist inspections cannot pass without sample coverage and a defective-piece count.'
                    );
                }
                $rows = $lockedInspection->measurements()->lockForUpdate()->get();
                $hasCompleteChecklist = $rows->isNotEmpty()
                    && $rows->groupBy(static fn (InspectionMeasurement $row): string => $row->checklistKey())
                        ->every(static fn ($group): bool => $group->first()->item_result !== null);
                if ($lockedInspection->recorded_result && $lockedInspection->inspected_quantity === null && ! $hasCompleteChecklist) {
                    throw new BusinessRuleException('Recorded sample coverage or completed inspection requirements are required before approving a quick batch PASS.');
                }
                if (! $lockedInspection->usesChecklistCapture()) {
                    $evidence = LotDefectCounter::for($lockedInspection, $rows);
                    if ($evidence['criticalFail'] || $evidence['defects'] > (int) $lockedInspection->accept_count) {
                        throw new BusinessRuleException('PASS contradicts saved inspection evidence.');
                    }
                }
            }
            if ($targetStatus === InspectionStatus::Failed || $targetStatus->value !== $proposed->value) {
                if (! $remarks) {
                    throw new BusinessRuleException('Remarks are required for a failed disposition or override.');
                }
            }

            $this->states->assertAllowed($lockedInspection, $targetStatus);
            $lockedInspection->forceFill([
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ])->save();

            return $this->finalizeTerminal($lockedInspection, $targetStatus, $by);
        });
    }

    /**
     * A result awaiting its checker blocks everything downstream (an outgoing
     * lot cannot be drafted for delivery), so the people who may check it are
     * told. Recipients are whoever holds the review route's permission, minus
     * the maker, who is refused at review time anyway.
     */
    private function notifyReviewers(Inspection $inspection, User $by): void
    {
        $makers = array_values(array_unique(array_filter([(int) $by->id, (int) $inspection->inspector_id])));
        $reviewers = app(NotificationService::class)->usersWithPermission('quality.inspections.review')
            ->whereNotIn('id', $makers)
            ->values();

        $stage = $inspection->stage instanceof \BackedEnum ? $inspection->stage->value : (string) $inspection->stage;
        $proposed = $inspection->proposed_result instanceof \BackedEnum
            ? $inspection->proposed_result->value
            : (string) $inspection->proposed_result;
        app(NotificationService::class)->send($reviewers, 'quality.inspection_awaiting_review', [
            'title' => "Inspection {$inspection->inspection_number} awaits your review",
            'message' => ucfirst(str_replace('_', '-', $stage))." inspection proposes {$proposed}."
                .' A second person must check it before the result is final.',
            'link_to' => "/quality/inspections/{$inspection->hash_id}",
            'entity_type' => 'inspection',
            'entity_id' => $inspection->hash_id,
        ]);
    }

    private function recordResultAuthor(Inspection $inspection, User $by): void
    {
        $inspection->resultAuthors()->firstOrCreate(['user_id' => $by->id]);
    }

    private function assertCriticalMeasurementsRecorded(Inspection $inspection): void
    {
        if ($inspection->usesFullPieceCapture()) {
            $this->assertFullPieceEvidence($inspection);
            if ((int) $inspection->sample_defect_count !== 0) {
                throw new BusinessRuleException('PASS requires zero defective pieces.');
            }

            return;
        }
        if ($inspection->counted_capture) {
            $this->assertCountedEvidence($inspection);
            if ($inspection->sample_defect_count !== 0 || $inspection->critical_defect
                || trim((string) $inspection->problem_description) !== ''
                || $inspection->measurements()->get()->contains(fn ($row) => $row->requiresSizeReading() && $row->evaluate() !== true)) {
                throw new BusinessRuleException('PASS requires zero defects and conforming required size readings.');
            }

            return;
        }
        if ($inspection->startup_trial_required && $inspection->measurements()->get()->contains(
            fn (InspectionMeasurement $row): bool => $row->requiresSizeReading() && ($row->measured_value === null || $row->evaluate() !== true),
        )) {
            throw new BusinessRuleException('Record conforming actual size readings before passing the startup trial.');
        }
        if ($inspection->usesChecklistCapture()) {
            $rows = $inspection->measurements()->get();
            $defects = $inspection->sample_defect_count;
            if ($rows->isEmpty() || $rows->contains(fn ($row) => $row->item_result !== 'passed' || trim((string) $row->acceptance_criterion) === ''
                || ($row->requiresSizeReading() && ($row->measured_value === null || $row->evaluate() !== true)))
                || $inspection->inspected_quantity === null || $inspection->sample_defect_count === null
                || $inspection->inspected_quantity < $inspection->sample_size || $inspection->inspected_quantity > $inspection->batch_quantity
                || $defects < 0 || $defects > $inspection->inspected_quantity
                || $defects > (int) $inspection->accept_count || $inspection->critical_defect
                || ($defects > 0 && ! $inspection->defects_removed_confirmed)) {
                throw new BusinessRuleException(
                    'A checklist PASS requires complete planned coverage, defects within the pinned acceptance count, no critical defects, and segregation of any accepted defects.'
                );
            }

            return;
        }
        $missing = $inspection->measurements()
            ->where('is_critical', true)
            ->where(static function ($query): void {
                $query->whereNotNull('tolerance_min')->orWhereNotNull('tolerance_max');
            })
            ->whereNull('measured_value')
            ->exists();

        if ($missing) {
            throw new BusinessRuleException('Measure every critical characteristic before passing the inspection.');
        }
    }

    private function finalizeTerminal(Inspection $inspection, InspectionStatus $targetStatus, User $by, ?int $defects = null): Inspection
    {
        app(WorkOrderOutputService::class)->assertTrialEvidence($inspection, null, true);
        if ($targetStatus === InspectionStatus::Passed) {
            $this->assertCriticalMeasurementsRecorded($inspection);
        }

        $inspection->forceFill([
            'affected_quantity' => $targetStatus === InspectionStatus::Failed && $inspection->startup_trial_required
                ? $inspection->processRecoveryQuantity() : $inspection->affected_quantity,
            'status' => $targetStatus->value,
            'defect_count' => $defects ?? (int) $inspection->defect_count,
            'accepted_quantity' => $targetStatus === InspectionStatus::Passed
                && $inspection->stage === InspectionStage::Outgoing
                ? max(0, (int) $inspection->batch_quantity - ($defects ?? (int) $inspection->defect_count))
                : 0,
            'completed_at' => now(),
            'inspector_id' => $inspection->inspector_id ?? $by->id,
        ])->save();
        $eventInspection = $inspection->fresh();

        if ($targetStatus === InspectionStatus::Failed) {
            if (! ($eventInspection->isCountOnlyIncoming()
                && (int) $eventInspection->sample_defect_count > 0
                && (int) $eventInspection->inspected_quantity === (int) $eventInspection->batch_quantity
                && $eventInspection->defects_removed_confirmed
                && ! $eventInspection->critical_defect)) {
                app(NcrService::class)->openFromInspectionFailure(
                    $eventInspection->load('measurements'),
                    $by,
                );
            }
            app(OutboxService::class)->record(new InspectionFailed($eventInspection));
        } else {
            app(WorkOrderOutputService::class)->settleTrial($eventInspection, $by, $eventInspection->processRecoveryQuantity());
            app(OutboxService::class)->record(new InspectionPassed($eventInspection));
        }
        if ($eventInspection->entity_type?->value === 'work_order') {
            $wo = WorkOrder::query()->find($eventInspection->entity_id);
            if ($wo) {
                app(WorkOrderService::class)->requestQualityClose($wo, $by);
            }
        }

        return $this->show($eventInspection);
    }

    public function cancel(Inspection $inspection, ?string $reason, User $by, ?int $expectedVersion = null): Inspection
    {
        if ($inspection->usesFullPieceCapture()) {
            throw new BusinessRuleException('A final work-order inspection cannot be cancelled.');
        }
        if ($inspection->status->isTerminal() || $inspection->status === InspectionStatus::AwaitingReview) {
            throw new BusinessRuleException('Inspection is already finalised.');
        }

        return DB::transaction(function () use ($inspection, $reason, $by, $expectedVersion) {
            app(WorkOrderOutputService::class)->lockTrialSource($inspection);
            if ($inspection->usesChecklistCapture()) {
                $this->lockInspectionSource($inspection);
            }
            // Cancellation is also terminal. Apply the same authoritative-row
            // rule as completion so a stale cancel request cannot undo a pass
            // or failure that already drove a downstream chain listener.
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);
            if ($lockedInspection->status->isTerminal() || $lockedInspection->status === InspectionStatus::AwaitingReview) {
                throw new BusinessRuleException('Inspection is already finalised.');
            }

            if ($lockedInspection->startup_trial_required && $lockedInspection->trialOutput()->exists()) {
                throw new BusinessRuleException('Captured trial pieces must keep their original inspection. Use quality recovery.');
            }
            $this->states->assertAllowed($lockedInspection, InspectionStatus::Cancelled);
            if ($lockedInspection->usesChecklistCapture() || $lockedInspection->counted_capture) {
                $this->assertResultVersion($lockedInspection, $expectedVersion);
                $lockedInspection->result_version++;
            }

            $lockedInspection->forceFill([
                'status' => InspectionStatus::Cancelled->value,
                'accepted_quantity' => 0,
                'completed_at' => now(),
                'notes' => trim(($lockedInspection->notes ? $lockedInspection->notes."\n" : '').'[cancelled] '.($reason ?: 'no reason given').' — by user#'.$by->id),
            ])->save();

            return $this->show($lockedInspection->fresh());
        });
    }

    /** Replace only open legacy evidence; never erase a known failure or release a hold. */
    public function replaceWithChecklist(Inspection $inspection, string $reason, int $expectedVersion, User $by): Inspection
    {
        $this->assertLegacyEntry($inspection);
        ChecklistDefinition::assertEnabled();
        if (trim($reason) === '') {
            throw new BusinessRuleException('Enter a reason for replacing the inspection.');
        }

        return DB::transaction(function () use ($inspection, $reason, $expectedVersion, $by): Inspection {
            $this->lockInspectionSource($inspection);
            $locked = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);
            if ($locked->startup_trial_required && $locked->trialOutput()->exists()) {
                throw new BusinessRuleException('Captured trial pieces must keep their original inspection. Use quality recovery.');
            }
            $successor = Inspection::query()->where('replaces_inspection_id', $locked->id)->first();
            if ($successor) {
                return $this->show($successor);
            }
            $this->assertResultVersion($locked, $expectedVersion);
            if ($locked->usesChecklistCapture() || ! in_array($locked->status, [InspectionStatus::Draft, InspectionStatus::InProgress], true)
                || (int) $locked->accepted_quantity > 0 || $locked->ncr()->exists()
                || $locked->measurements()->where(function ($q) {
                    $q->where('item_result', 'failed')->orWhere('is_pass', false);
                })->exists()) {
                throw new BusinessRuleException('Only open legacy inspections without known failures can be replaced. Use the existing recovery process for failures.');
            }
            if ($locked->measurements()->get()->contains(fn ($row) => $row->evaluate() === false)) {
                throw new BusinessRuleException('Failed measurement evidence must continue through quality recovery.');
            }
            if ($locked->entity_type === InspectionEntityType::WorkOrder) {
                $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($locked->entity_id);
                if (in_array($workOrder->status->value, ['closed', 'cancelled'], true)) {
                    throw new BusinessRuleException('Closed or cancelled production cannot be reopened by replacing QC.');
                }
            }
            if ($locked->entity_type === InspectionEntityType::Grn) {
                $grn = GoodsReceiptNote::query()->lockForUpdate()->findOrFail($locked->entity_id);
                if ($grn->status->value !== 'pending_qc') {
                    throw new BusinessRuleException('Only held pending receipts can replace their inspection.');
                }
            }
            $this->cancel($locked, $reason, $by);
            if ($locked->grn_item_id) {
                $line = GrnItem::query()->with('item')->findOrFail($locked->grn_item_id);
                $grn = GoodsReceiptNote::query()->findOrFail($locked->entity_id);
                $plan = app(ItemQualityPlanService::class)->activeFor($line->item, $grn->vendor_id);
                $next = $plan ? $this->createIncomingFromPlan($plan, $line, $grn, $by)
                    : $this->createIncomingForItem($line->item, (int) $locked->batch_quantity, $grn->id, $by, $reason, $line->id);
                $grn->forceFill(['qc_inspection_id' => $next->id])->save();
            } elseif ($locked->product_id) {
                $next = $this->create(['stage' => $locked->stage->value, 'product_id' => $locked->product_id,
                    'batch_quantity' => $locked->batch_quantity, 'entity_type' => $locked->entity_type?->value,
                    'entity_id' => $locked->entity_id, 'wo_operation_id' => $locked->wo_operation_id,
                    'work_order_output_id' => $locked->work_order_output_id, 'notes' => $reason], $by);
            } else {
                throw new BusinessRuleException('This inspection needs source reconciliation before replacement.');
            }
            if (! $next->usesChecklistCapture()) {
                throw new BusinessRuleException('Publish the approved PASS/FAIL checklist before replacing the inspection.');
            }
            $next->forceFill(['replaces_inspection_id' => $locked->id])->save();
            if ($locked->entity_type === InspectionEntityType::ReturnRequest) {
                ReturnRequest::query()->whereKey($locked->entity_id)->where('inspection_id', $locked->id)
                    ->update(['inspection_id' => $next->id]);
            }

            return $this->show($next->fresh());
        });
    }

    /**
     * Scaffold a lot-checklist inspection.
     *
     * One checklist row (sample_index = 1, no tolerance bounds) for every
     * parameter with no tolerance band, and `$measuredPieces` piece rows
     * (sample_index 1..N, with bounds) for every parameter that has one. The
     * split is not cosmetic: InspectionService::recordLotResult() rejects a
     * checklist row carrying bounds and a piece row lacking them, so the shape
     * written here is the shape the recording endpoint accepts.
     *
     * @param  iterable<array{parameter_name: string, parameter_type: string, unit_of_measure: ?string, nominal_value: mixed, tolerance_min: mixed, tolerance_max: mixed, is_critical: bool, notes: ?string, inspection_spec_item_id: ?int}>  $parameters
     *
     * @throws BusinessRuleException when nothing usable was supplied — an
     *                               inspection with no rows cannot be completed later.
     */
    private function scaffoldLotChecklist(Inspection $inspection, iterable $parameters, int $measuredPieces): void
    {
        $timestamp = now()->toDateTimeString();
        $rows = [];

        foreach ($parameters as $parameter) {
            $name = trim((string) ($parameter['parameter_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if ($inspection->usesChecklistCapture() && trim((string) ($parameter['acceptance_criterion'] ?? '')) === '') {
                throw new BusinessRuleException('Every approved checklist check needs an acceptance criterion.');
            }
            $hasTolerance = (! $inspection->usesChecklistCapture() || config('inspection.counted_capture_enabled')) && (($parameter['tolerance_min'] ?? null) !== null || ($parameter['tolerance_max'] ?? null) !== null);
            $sampleIndices = $hasTolerance
                ? range(1, min($measuredPieces, max(1, (int) $inspection->sample_size)))
                : [1];

            foreach ($sampleIndices as $sampleIndex) {
                $rows[] = [
                    'inspection_id' => $inspection->id,
                    'inspection_spec_item_id' => $parameter['inspection_spec_item_id'] ?? null,
                    'sample_index' => $sampleIndex,
                    'parameter_name' => $name,
                    'parameter_type' => $parameter['parameter_type'] ?? null,
                    'acceptance_criterion' => config('inspection.counted_capture_enabled') ? ChecklistDefinition::criterion($parameter) : ($parameter['acceptance_criterion'] ?? null),
                    'unit_of_measure' => $parameter['unit_of_measure'] ?? null,
                    'nominal_value' => $parameter['nominal_value'] ?? null,
                    'tolerance_min' => $parameter['tolerance_min'] ?? null,
                    'tolerance_max' => $parameter['tolerance_max'] ?? null,
                    'measured_value' => null,
                    'is_critical' => (bool) ($parameter['is_critical'] ?? false),
                    'is_pass' => null,
                    'notes' => $parameter['notes'] ?? null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        if ($rows === []) {
            throw new BusinessRuleException(
                'Lot checklist inspection requires at least one inspectable parameter.'
            );
        }

        foreach (array_chunk($rows, 500) as $batch) {
            InspectionMeasurement::query()->insert($batch);
        }
    }

    /**
     * Insert a sample × parameter scaffold in bounded batches.
     *
     * @param  iterable<mixed>  $parameters
     * @param  callable(int, mixed, int, string): array<string, mixed>  $rowFactory
     */
    private function insertScaffoldRows(
        int $inspectionId,
        int $sampleSize,
        iterable $parameters,
        callable $rowFactory,
    ): void {
        $rows = [];
        $timestamp = now()->toDateTimeString();

        for ($sampleIndex = 1; $sampleIndex <= $sampleSize; $sampleIndex++) {
            foreach ($parameters as $parameter) {
                $rows[] = $rowFactory($sampleIndex, $parameter, $inspectionId, $timestamp);
                if (count($rows) >= 500) {
                    InspectionMeasurement::query()->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            InspectionMeasurement::query()->insert($rows);
        }
    }

    private function boundedFullSampleSize(int $batchQuantity): int
    {
        $maximum = $this->settings->requiredInt('quality.full_sampling.max_units', 1, 1000000);
        if ($batchQuantity > $maximum) {
            throw new BusinessRuleException(
                "Full sampling is limited to {$maximum} unit(s); use a finite quality plan for larger batches.",
            );
        }

        return $batchQuantity;
    }

    /**
     * True when a QueryException is a unique-constraint violation. SQLSTATE
     * 23000/23505 covers PostgreSQL; SQLite surfaces HY000 but embeds the
     * message. Same helper the chain listeners carry.
     */
    private static function isUniqueViolation(QueryException $e): bool
    {
        return str_starts_with((string) $e->getCode(), '23')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * Pieces measured per toleranced parameter in a lot-checklist inspection.
     *
     * The single reader of both keys: the stage-agnostic setting, falling back
     * to its incoming-only predecessor for one release. Resolving here rather
     * than at each call site keeps the two keys from being read inconsistently.
     */
    private function measuredPieces(): int
    {
        $value = $this->settings->get('quality.inspection.measured_pieces')
            ?? $this->settings->get('quality.incoming.measured_pieces');

        if ($value === null) {
            return 5;
        }

        if (! is_numeric($value) || (int) $value != (float) $value) {
            throw new BusinessRuleException('Required setting quality.inspection.measured_pieces is missing or invalid.');
        }

        $pieces = (int) $value;
        if ($pieces < 1 || $pieces > 1000) {
            throw new BusinessRuleException('Required setting quality.inspection.measured_pieces is outside its valid range.');
        }

        return $pieces;
    }

    private function attachEntityContext(Inspection $inspection): Inspection
    {
        $type = $inspection->entity_type instanceof InspectionEntityType
            ? $inspection->entity_type->value
            : (string) $inspection->entity_type;

        if (! $inspection->entity_id) {
            return $inspection->setRelation('entityRecord', null);
        }

        $record = match ($type) {
            InspectionEntityType::Grn->value => GoodsReceiptNote::query()
                ->select(['id', 'grn_number', 'status'])
                ->find($inspection->entity_id),
            InspectionEntityType::WorkOrder->value => WorkOrder::query()
                ->select(['id', 'wo_number', 'status'])
                ->find($inspection->entity_id),
            InspectionEntityType::Delivery->value => Delivery::query()
                ->select(['id', 'delivery_number', 'status'])
                ->find($inspection->entity_id),
            InspectionEntityType::ReturnRequest->value => ReturnRequest::query()
                ->select(['id', 'rma_number', 'status'])
                ->find($inspection->entity_id),
            default => null,
        };

        return $inspection->setRelation('entityRecord', $record);
    }
}
