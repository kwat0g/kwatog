<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\ItemQualityPlan;
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
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Inspection::query()
            ->with([
                'product:id,part_number,name',
                'item:id,code,name',
                'inspector:id,name,role_id',
                'spec:id,product_id,version',
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
        return $inspection->load([
            'product:id,part_number,name',
            'item:id,code,name',
            'inspector:id,name,role_id',
            'spec:id,product_id,version,is_active',
            'qualityPlan:id,item_id,vendor_id,version,sampling_method',
            'workOrderOutput.workOrder:id,wo_number,product_id',
            'measurements' => fn ($q) => $q->orderBy('sample_index')->orderBy('id'),
        ]);
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

        return DB::transaction(function () use ($item, $batchQuantity, $grnId, $by, $notes, $plan, $grnItemId) {
            $inspection = Inspection::query()->create([
                'inspection_number' => $this->sequences->generate('inspection'),
                'stage' => InspectionStage::Incoming->value,
                'status' => InspectionStatus::Draft->value,
                'product_id' => null,
                'item_id' => $item->id,
                'entity_type' => InspectionEntityType::Grn->value,
                'entity_id' => $grnId,
                'grn_item_id' => $grnItemId,
                'batch_quantity' => $batchQuantity,
                'sample_size' => (int) $plan['sample_size'],
                'aql_code' => (string) $plan['code'],
                'accept_count' => (int) $plan['accept'],
                'reject_count' => (int) $plan['reject'],
                'defect_count' => 0,
                'inspector_id' => $by?->id,
                'notes' => $notes,
            ]);

            InspectionMeasurement::query()->create([
                'inspection_id' => $inspection->id,
                'sample_index' => 1,
                'parameter_name' => 'Overall incoming material verdict',
                'parameter_type' => 'visual',
                'is_critical' => true,
                'is_pass' => null,
            ]);

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
        $batchQuantity = (int) (float) $line->quantity_received;
        if ($batchQuantity < 1) {
            throw new BusinessRuleException('Incoming inspection requires a positive received quantity.');
        }
        $aql = AqlSampleSizeService::forBatch($batchQuantity);
        $sampleSize = match ($qualityPlan->sampling_method) {
            'full' => $batchQuantity,
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

            $rows = [];
            $now = now();
            foreach (range(1, $sampleSize) as $sampleIndex) {
                foreach ($qualityPlan->parameters as $parameter) {
                    $rows[] = [
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
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                InspectionMeasurement::query()->insert($chunk);
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
            ->with('items')
            ->first();

        if (! $spec) {
            throw new BusinessRuleException("Product {$product->part_number} has no active inspection spec.");
        }
        if ($spec->items->isEmpty()) {
            throw new BusinessRuleException("Inspection spec for {$product->part_number} has no parameters.");
        }

        // AQL plan only applies to outgoing. Incoming + in-process default to
        // 100% inspection of the batch; the inspector may override the
        // sample size by patching the row before recording measurements.
        if ($stage === InspectionStage::Outgoing) {
            $plan = AqlSampleSizeService::forBatch($batchQty);
            $sample = $plan['sample_size'];
            $code = $plan['code'];
            $accept = $plan['accept'];
            $reject = $plan['reject'];
        } else {
            $sample = $batchQty;
            $code = null;
            $accept = 0;
            $reject = 1;
        }

        return DB::transaction(function () use (
            $stage, $product, $spec, $batchQty, $sample, $code, $accept, $reject, $by, $data, $output
        ) {
            $insp = Inspection::query()->create([
                'inspection_number' => $this->sequences->generate('inspection'),
                'stage' => $stage->value,
                'status' => InspectionStatus::Draft->value,
                'product_id' => $product->id,
                'inspection_spec_id' => $spec->id,
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
                'started_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            // Seed one measurement row per (sample × spec_item).
            $rows = [];
            $now = now();
            foreach (range(1, $sample) as $sampleIndex) {
                /** @var InspectionSpecItem $item */
                foreach ($spec->items as $item) {
                    $rows[] = [
                        'inspection_id' => $insp->id,
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
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            // Bulk insert in chunks to keep memory bounded for large samples.
            foreach (array_chunk($rows, 500) as $chunk) {
                InspectionMeasurement::query()->insert($chunk);
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

            $measurements = InspectionMeasurement::query()
                ->where('inspection_id', $lockedInspection->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($rows as $id => $patch) {
                /** @var InspectionMeasurement|null $m */
                $m = $measurements->get((int) $id);
                if (! $m) {
                    continue;
                }

                if (array_key_exists('measured_value', $patch)) {
                    $m->measured_value = $patch['measured_value'] === '' || $patch['measured_value'] === null
                        ? null
                        : (string) $patch['measured_value'];
                }
                if (array_key_exists('notes', $patch)) {
                    $m->notes = $patch['notes'] !== '' ? $patch['notes'] : null;
                }

                // Numeric parameter with a tolerance window → auto-evaluate.
                $auto = $m->evaluate();
                if ($auto !== null) {
                    $m->is_pass = $auto;
                } elseif (array_key_exists('is_pass', $patch)) {
                    $m->is_pass = $patch['is_pass'] === null ? null : (bool) $patch['is_pass'];
                }

                $m->save();
            }

            // Recompute defect_count and bump status to in_progress.
            $defects = InspectionMeasurement::query()
                ->where('inspection_id', $lockedInspection->id)
                ->where('is_pass', false)
                ->count();

            $lockedInspection->forceFill([
                'defect_count' => $defects,
                'status' => InspectionStatus::InProgress->value,
                'inspector_id' => $lockedInspection->inspector_id ?? $by->id,
            ])->save();

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
        if ($inspection->status->isTerminal()) {
            throw new BusinessRuleException('Inspection is already finalised.');
        }

        return DB::transaction(function () use ($inspection, $by) {
            // The caller may hold a stale in-progress model after another
            // worker has already completed the inspection. Lock and recheck
            // the authoritative row so terminal outcomes are immutable.
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);
            if ($lockedInspection->status->isTerminal()) {
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

            $criticalFail = $rows->contains(fn (InspectionMeasurement $r) => $r->is_critical && $r->is_pass === false);
            $defects = $rows->where('is_pass', false)->count();
            $accept = (int) $lockedInspection->accept_count;

            $passed = ! $criticalFail && $defects <= $accept;

            $lockedInspection->forceFill([
                'status' => $passed ? InspectionStatus::Passed->value : InspectionStatus::Failed->value,
                'defect_count' => $defects,
                'accepted_quantity' => $passed && $lockedInspection->stage === InspectionStage::Outgoing
                    ? (int) $lockedInspection->batch_quantity
                    : 0,
                'completed_at' => now(),
                'inspector_id' => $lockedInspection->inspector_id ?? $by->id,
            ])->save();
            $eventInspection = $lockedInspection->fresh();

            // Sprint 7 Task 61: auto-open an NCR when the inspection failed.
            // Keep the corrective-action record in this transaction: a failed
            // inspection without its NCR is an IATF traceability gap, and an
            // afterCommit callback could be lost if the worker dies first.
            if (! $passed) {
                app(NcrService::class)->openFromInspectionFailure(
                    $eventInspection->load('measurements'),
                    $by,
                );

                // The failure cascade is also recorded in this transaction;
                // queue publication waits for commit and is replayable.
                app(OutboxService::class)->record(
                    new InspectionFailed($eventInspection),
                );
            } else {
                // Inspection passed → drive the outgoing-delivery /
                // incoming-bill cascades through the durable outbox.
                app(OutboxService::class)->record(
                    new InspectionPassed($eventInspection),
                );
            }

            return $this->show($eventInspection);
        });
    }

    public function cancel(Inspection $inspection, ?string $reason, User $by): Inspection
    {
        if ($inspection->status->isTerminal()) {
            throw new BusinessRuleException('Inspection is already finalised.');
        }

        return DB::transaction(function () use ($inspection, $reason, $by) {
            // Cancellation is also terminal. Apply the same authoritative-row
            // rule as completion so a stale cancel request cannot undo a pass
            // or failure that already drove a downstream chain listener.
            $lockedInspection = Inspection::query()
                ->lockForUpdate()
                ->findOrFail($inspection->id);
            if ($lockedInspection->status->isTerminal()) {
                throw new BusinessRuleException('Inspection is already finalised.');
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
}
