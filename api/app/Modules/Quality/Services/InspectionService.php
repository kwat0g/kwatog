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
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
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
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Support\InspectionStateMachine;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Inspection::query()
            ->with([
                'product:id,part_number,name',
                'item:id,code,name',
                'inspector:id,name,role_id',
                'reviewer:id,name,role_id',
                'resultAuthors:id,inspection_id,user_id',
                'spec:id,product_id,version',
                'specRevision:id,inspection_spec_id,version,created_by,notes',
                'qualityPlan:id,item_id,vendor_id,version,sampling_method',
                'workOrderOutput.workOrder:id,wo_number,product_id',
            ]);

        if (! empty($filters['stage'])) {
            $q->where('stage', $filters['stage']);
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
                    ->orWhere('name', SearchOperator::like(), $term)));
        }

        return $q->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
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
            'item:id,code,name',
            'inspector:id,name,role_id',
            'reviewer:id,name,role_id',
            'resultAuthors:id,inspection_id,user_id',
            'spec:id,product_id,version,is_active',
            'specRevision:id,inspection_spec_id,version,created_by,notes',
            'specRevision.creator:id,name,role_id',
            'specRevision.items',
            'grnItem:id,goods_receipt_note_id,item_id,quantity_received,quantity_accepted',
            'grnItem.grn:id,grn_number,status',
            'qualityPlan:id,item_id,vendor_id,version,sampling_method',
            'workOrderOutput.workOrder:id,wo_number,product_id',
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

        return DB::transaction(function () use ($item, $batchQuantity, $grnId, $by, $notes, $plan, $sampleSize, $grnItemId) {
            $inspection = Inspection::query()->create([
                'inspection_number' => $this->sequences->generate('inspection'),
                'stage' => InspectionStage::Incoming->value,
                'status' => InspectionStatus::Draft->value,
                'inspection_mode' => InspectionMode::LotChecklist->value,
                'product_id' => null,
                'item_id' => $item->id,
                'entity_type' => InspectionEntityType::Grn->value,
                'entity_id' => $grnId,
                'grn_item_id' => $grnItemId,
                'batch_quantity' => $batchQuantity,
                'sample_size' => $sampleSize,
                'aql_code' => (string) $plan['code'],
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
                    'parameter_type' => InspectionParameterType::Visual->value,
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
                ->whereNull('qc_inspection_id')
                ->update(['qc_inspection_id' => $inspection->id, 'updated_at' => now()]);

            return $this->show($inspection);
        });
    }

    /** Create an incoming inspection from the effective item/vendor quality-plan revision. */
    public function createIncomingFromPlan(
        ItemQualityPlan $qualityPlan,
        GrnItem $line,
        GoodsReceiptNote $grn,
        ?User $by = null,
    ): Inspection {
        $qtyReceived = (string) $line->quantity_received;
        if (bccomp($qtyReceived, '0', 3) <= 0) {
            throw new BusinessRuleException('Incoming inspection requires a positive received quantity.');
        }
        $truncated = (int) bcdiv($qtyReceived, '1', 0);
        $batchQuantity = bccomp($qtyReceived, (string) $truncated, 3) > 0
            ? $truncated + 1
            : max(1, $truncated);
        $aql = AqlSampleSizeService::forBatch($batchQuantity);
        $sampleSize = match ($qualityPlan->sampling_method) {
            'full' => $this->boundedFullSampleSize($batchQuantity),
            'fixed' => min($batchQuantity, max(1, (int) $qualityPlan->fixed_sample_size)),
            default => (int) $aql['sample_size'],
        };
        $accept = $qualityPlan->sampling_method === 'aql' ? (int) $aql['accept'] : 0;
        $reject = $qualityPlan->sampling_method === 'aql' ? (int) $aql['reject'] : 1;

        return DB::transaction(function () use ($qualityPlan, $line, $grn, $by, $batchQuantity, $sampleSize, $aql, $accept, $reject) {
            $inspection = Inspection::query()->create([
                'inspection_number' => $this->sequences->generate('inspection'),
                'stage' => InspectionStage::Incoming->value,
                'status' => InspectionStatus::Draft->value,
                'inspection_mode' => InspectionMode::LotChecklist->value,
                'item_id' => $line->item_id,
                'item_quality_plan_id' => $qualityPlan->id,
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

            // For lot-checklist mode: split parameters into checklist (no tolerance)
            // and piece rows (with tolerance).
            $timestamp = now()->toDateTimeString();
            $measuredPieces = $this->settings->requiredInt('quality.incoming.measured_pieces', 1, 1000);
            $checklistRows = [];
            $pieceRows = [];

            foreach ((array) $qualityPlan->parameters as $parameter) {
                $hasToler = isset($parameter['tolerance_min']) && isset($parameter['tolerance_max']);
                if (! $hasToler) {
                    // Checklist row: sample_index = 1 only.
                    $checklistRows[] = [
                        'inspection_id' => $inspection->id,
                        'inspection_spec_item_id' => null,
                        'sample_index' => 1,
                        'parameter_name' => $parameter['parameter_name'],
                        'parameter_type' => $parameter['parameter_type'],
                        'unit_of_measure' => $parameter['unit_of_measure'] ?? null,
                        'nominal_value' => $parameter['nominal_value'] ?? null,
                        'tolerance_min' => null,
                        'tolerance_max' => null,
                        'measured_value' => null,
                        'is_critical' => (bool) ($parameter['is_critical'] ?? false),
                        'is_pass' => null,
                        'notes' => $parameter['notes'] ?? null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                } else {
                    // Piece rows: sample_index = 1..min(measured_pieces, sample_size).
                    for ($sampleIndex = 1; $sampleIndex <= min($measuredPieces, $sampleSize); $sampleIndex++) {
                        $pieceRows[] = [
                            'inspection_id' => $inspection->id,
                            'inspection_spec_item_id' => null,
                            'sample_index' => $sampleIndex,
                            'parameter_name' => $parameter['parameter_name'],
                            'parameter_type' => $parameter['parameter_type'],
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
            }

            $allRows = array_merge($checklistRows, $pieceRows);
            if ($allRows !== []) {
                // Batch insert to avoid OOM on large sets.
                foreach (array_chunk($allRows, 500) as $batch) {
                    InspectionMeasurement::query()->insert($batch);
                }
            }

            GoodsReceiptNote::query()->whereKey($grn->id)->whereNull('qc_inspection_id')
                ->update(['qc_inspection_id' => $inspection->id, 'updated_at' => now()]);

            return $this->show($inspection);
        });
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
        $stage = InspectionStage::from((string) $data['stage']);
        $productId = (int) $data['product_id'];
        $batchQty = (int) $data['batch_quantity'];
        if ($batchQty < 1) {
            throw new BusinessRuleException('Inspection requires a positive batch quantity.');
        }

        $product = Product::query()->findOrFail($productId);
        $output = null;
        if ($stage === InspectionStage::Outgoing) {
            $outputId = (int) ($data['work_order_output_id'] ?? 0);
            if ($outputId < 1) {
                throw new BusinessRuleException('Outgoing inspection requires a specific work-order output batch.');
            }

            $output = WorkOrderOutput::query()->with('workOrder')->findOrFail($outputId);
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
        $existing = Inspection::query()
            ->when($stage === InspectionStage::Outgoing,
                fn ($query) => $query->where('work_order_output_id', $output?->id),
                fn ($query) => $query
                    ->where('stage', $stage->value)
                    ->where('entity_type', $data['entity_type'] ?? null)
                    ->where('entity_id', $data['entity_id'] ?? null)
                    ->when(
                        in_array($stage, [InspectionStage::CustomerReturn, InspectionStage::SupplierReturn], true),
                        fn ($query) => $query->where('product_id', $product->id),
                    ),
            )
            ->first();
        if ($existing) {
            return $this->show($existing);
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
            $sample = $this->boundedFullSampleSize($batchQty);
            $code = null;
            $accept = 0;
            $reject = 1;
        }

        return DB::transaction(function () use (
            $stage, $product, $spec, $batchQty, $sample, $code, $accept, $reject, $by, $data, $output, $calibrationRecordId
        ) {
            $insp = Inspection::query()->create([
                'inspection_number' => $this->sequences->generate('inspection'),
                'stage' => $stage->value,
                'status' => InspectionStatus::Draft->value,
                'product_id' => $product->id,
                'inspection_spec_id' => $spec->id,
                'inspection_spec_revision_id' => $spec->currentRevision?->id,
                'entity_type' => isset($data['entity_type']) ? InspectionEntityType::from((string) $data['entity_type'])->value : null,
                'entity_id' => isset($data['entity_id']) ? (int) $data['entity_id'] : null,
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

            // Seed one measurement row per (sample × spec_item) without
            // retaining the complete matrix in memory for a large lot.
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

            return $this->show($insp);
        });
    }

    /**
     * Patch measurement readings. Each input row is keyed by measurement id
     * and may set measured_value, is_pass, notes. Auto-evaluation overrides
     * the explicit is_pass for numeric parameters that have a tolerance band.
     *
     * @param  array<int, array{measured_value?: float|string|null, is_pass?: bool|null, notes?: string|null}>  $rows
     */
    public function recordMeasurements(Inspection $inspection, array $rows, User $by): Inspection
    {
        if ($inspection->status->isTerminal()) {
            throw new BusinessRuleException('Inspection is already finalised.');
        }
        if ($inspection->status === InspectionStatus::AwaitingReview) {
            throw new BusinessRuleException('Inspection is awaiting checker review and its evidence is locked.');
        }

        return DB::transaction(function () use ($inspection, $rows, $by) {
            // Route-bound inspection models can be stale when a completion or
            // cancellation races measurement entry. Serialize the lifecycle
            // on the inspection row before touching its measurements.
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);
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
                    // A tolerance-backed parameter is evidence-bearing: it
                    // cannot be marked pass/fail without a reading, and an
                    // explicit result may not contradict the calculated one.
                    $auto = $m->evaluate();
                    if (array_key_exists('is_pass', $patch)
                        && $patch['is_pass'] !== null
                        && ($auto === null || (bool) $patch['is_pass'] !== $auto)) {
                        throw new BusinessRuleException(
                            "Measurement {$m->parameter_name} requires a result derived from its measured value.",
                        );
                    }
                    $m->is_pass = $auto;
                    $hasMeaningfulResultEdit = $hasMeaningfulResultEdit || $m->isDirty('is_pass');
                } elseif (array_key_exists('is_pass', $patch)) {
                    // Manual visual/functional parameters use an explicit
                    // verdict because they have no tolerance window.
                    $m->is_pass = $patch['is_pass'] === null ? null : (bool) $patch['is_pass'];
                    $hasMeaningfulResultEdit = $hasMeaningfulResultEdit || $m->isDirty('is_pass');
                }

                $m->save();
            }

            if ($hasMeaningfulResultEdit && $lockedInspection->requiresMakerChecker()) {
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
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);
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

                    // Piece rows must have a tolerance.
                    if ($row->tolerance_min === null || $row->tolerance_max === null) {
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
                if ($lockedInspection->sample_defect_count !== $defectCount
                    && $lockedInspection->requiresMakerChecker()) {
                    $this->recordResultAuthor($lockedInspection, $by);
                }
                $lockedInspection->forceFill(['sample_defect_count' => $defectCount])->save();
            }

            // If complete=true, finalize the inspection.
            if ($data['complete'] ?? false) {
                return $this->complete($lockedInspection->fresh(), $by);
            }

            return $this->show($lockedInspection->fresh());
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
        if ($inspection->status->isTerminal() || $inspection->status === InspectionStatus::AwaitingReview) {
            throw new BusinessRuleException('Inspection is already finalised.');
        }

        return DB::transaction(function () use ($inspection, $by) {
            // The caller may hold a stale in-progress model after another
            // worker has already completed the inspection. Lock and recheck
            // the authoritative row so terminal outcomes are immutable.
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);
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

            $unresolved = $rows->whereNull('is_pass')->count();
            if ($unresolved > 0) {
                throw new BusinessRuleException("Cannot complete: {$unresolved} measurement(s) have no pass/fail recorded.");
            }

            // For lot_checklist mode, we use sample_defect_count (reported defects)
            // combined with any critical failures. For per_unit mode, we count
            // distinct sample indices with failures.
            if ($lockedInspection->inspection_mode === InspectionMode::LotChecklist) {
                if ($lockedInspection->sample_defect_count === null) {
                    throw new BusinessRuleException(
                        'Enter the number of defective pieces found in the sample (0 if none).'
                    );
                }

                // Defect count is the max of reported defects and any failed piece rows.
                // Checklist (sample_index=1, no tolerance) failures go to critical_fail.
                $reportedDefects = (int) $lockedInspection->sample_defect_count;
                $failedPieces = $rows
                    ->where('is_pass', false)
                    ->where(fn (InspectionMeasurement $r) => $r->tolerance_min !== null || $r->tolerance_max !== null)
                    ->pluck('sample_index')
                    ->unique()
                    ->count();
                $defects = max($reportedDefects, $failedPieces);
                $criticalFail = $rows->contains(fn (InspectionMeasurement $r) => $r->is_critical && $r->is_pass === false);
            } else {
                // Per-unit mode: count distinct sample indices with any failure.
                $sampledUnits = $rows->pluck('sample_index')->unique()->count();
                $declaredSample = (int) $lockedInspection->sample_size;
                if ($declaredSample > 0 && $sampledUnits < $declaredSample) {
                    throw new BusinessRuleException(
                        "Cannot complete: inspection declares a sample of {$declaredSample} unit(s) but only {$sampledUnits} were measured.",
                    );
                }

                $criticalFail = $rows->contains(fn (InspectionMeasurement $r) => $r->is_critical && $r->is_pass === false);
                $defects = $rows->where('is_pass', false)->pluck('sample_index')->unique()->count();
            }

            if ($lockedInspection->calibration_record_id) {
                $record = CalibrationRecord::query()->find((int) $lockedInspection->calibration_record_id);
                if ($record && in_array($record->status, [CalibrationStatus::Overdue, CalibrationStatus::Retired], true)) {
                    throw new BusinessRuleException(
                        "Cannot complete: measuring equipment {$record->equipment_code} is {$record->status->value}."
                    );
                }
            }

            $accept = (int) $lockedInspection->accept_count;
            $passed = ! $criticalFail && $defects <= $accept;
            $targetStatus = $passed ? InspectionStatus::Passed : InspectionStatus::Failed;

            // Completion may legally resolve a fully submitted draft. The
            // checker gate still records the intermediate lifecycle step.
            if ($lockedInspection->status === InspectionStatus::Draft) {
                $lockedInspection->setAttribute('status', InspectionStatus::InProgress);
            }

            if ($lockedInspection->requiresMakerChecker()) {
                $this->states->assertAllowed($lockedInspection, InspectionStatus::AwaitingReview);
                $this->recordResultAuthor($lockedInspection, $by);

                $lockedInspection->forceFill([
                    'status' => InspectionStatus::AwaitingReview->value,
                    'proposed_result' => $targetStatus->value,
                    'defect_count' => $defects,
                    'accepted_quantity' => 0,
                    'completed_at' => null,
                    'inspector_id' => $lockedInspection->inspector_id ?? $by->id,
                ])->save();
                $this->notifyReviewers($lockedInspection, $by);

                return $this->show($lockedInspection->fresh());
            }

            // A direct completion of a fully resolved draft is kept
            // compatible with existing callers, but still passes through the
            // same explicit draft → in_progress → terminal transition map.
            $this->states->assertAllowed($lockedInspection, $targetStatus);

            return $this->finalizeTerminal($lockedInspection, $targetStatus, $by, $defects);
        });
    }

    /**
     * Finalize a high-risk result after a different user has checked it.
     */
    public function review(Inspection $inspection, string $decision, ?string $remarks, User $by): Inspection
    {
        $targetStatus = InspectionStatus::tryFrom($decision);
        if (! in_array($targetStatus, [InspectionStatus::Passed, InspectionStatus::Failed], true)) {
            throw new BusinessRuleException('Inspection review decision must be passed or failed.');
        }

        return DB::transaction(function () use ($inspection, $targetStatus, $remarks, $by) {
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);

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
        $reviewers = User::query()
            ->where('is_active', true)
            ->whereNotIn('id', $makers)
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'quality.inspections.review'))
            ->get();

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

    private function finalizeTerminal(Inspection $inspection, InspectionStatus $targetStatus, User $by, ?int $defects = null): Inspection
    {
        $inspection->forceFill([
            'status' => $targetStatus->value,
            'defect_count' => $defects ?? (int) $inspection->defect_count,
            'accepted_quantity' => $targetStatus === InspectionStatus::Passed
                && $inspection->stage === InspectionStage::Outgoing
                ? (int) $inspection->batch_quantity
                : 0,
            'completed_at' => now(),
            'inspector_id' => $inspection->inspector_id ?? $by->id,
        ])->save();
        $eventInspection = $inspection->fresh();

        if ($targetStatus === InspectionStatus::Failed) {
            app(NcrService::class)->openFromInspectionFailure(
                $eventInspection->load('measurements'),
                $by,
            );
            app(OutboxService::class)->record(new InspectionFailed($eventInspection));
        } else {
            app(OutboxService::class)->record(new InspectionPassed($eventInspection));
        }

        return $this->show($eventInspection);
    }

    public function cancel(Inspection $inspection, ?string $reason, User $by): Inspection
    {
        if ($inspection->status->isTerminal() || $inspection->status === InspectionStatus::AwaitingReview) {
            throw new BusinessRuleException('Inspection is already finalised.');
        }

        return DB::transaction(function () use ($inspection, $reason, $by) {
            // Cancellation is also terminal. Apply the same authoritative-row
            // rule as completion so a stale cancel request cannot undo a pass
            // or failure that already drove a downstream chain listener.
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);
            if ($lockedInspection->status->isTerminal() || $lockedInspection->status === InspectionStatus::AwaitingReview) {
                throw new BusinessRuleException('Inspection is already finalised.');
            }

            $this->states->assertAllowed($lockedInspection, InspectionStatus::Cancelled);

            $lockedInspection->forceFill([
                'status' => InspectionStatus::Cancelled->value,
                'accepted_quantity' => 0,
                'completed_at' => now(),
                'notes' => trim(($lockedInspection->notes ? $lockedInspection->notes."\n" : '').'[cancelled] '.($reason ?: 'no reason given').' — by user#'.$by->id),
            ])->save();

            return $this->show($lockedInspection->fresh());
        });
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
