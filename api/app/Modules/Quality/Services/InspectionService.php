<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
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
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
            ]);

        if (! empty($filters['stage'])) {
            $q->where('stage', $filters['stage']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['product_id'])) {
            $q->where('product_id', $filters['product_id']);
        }
        if (! empty($filters['entity_type']) && ! empty($filters['entity_id'])) {
            $q->where('entity_type', $filters['entity_type'])
                ->where('entity_id', (int) $filters['entity_id']);
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

    public function show(Inspection $inspection): Inspection
    {
        return $inspection->load([
            'product:id,part_number,name',
            'item:id,code,name',
            'inspector:id,name,role_id',
            'spec:id,product_id,version,is_active',
            'qualityPlan:id,item_id,vendor_id,version,sampling_method',
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
        $batchQuantity = max(1, $batchQuantity);
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
        $batchQuantity = max(1, (int) (float) $line->quantity_received);
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
        $batchQty = max(1, (int) $data['batch_quantity']);

        $product = Product::query()->findOrFail($productId);
        $spec = InspectionSpec::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->with('items')
            ->first();

        if (! $spec) {
            throw new RuntimeException("Product {$product->part_number} has no active inspection spec.");
        }
        if ($spec->items->isEmpty()) {
            throw new RuntimeException("Inspection spec for {$product->part_number} has no parameters.");
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
            $stage, $product, $spec, $batchQty, $sample, $code, $accept, $reject, $by, $data
        ) {
            $insp = Inspection::query()->create([
                'inspection_number' => $this->sequences->generate('inspection'),
                'stage' => $stage->value,
                'status' => InspectionStatus::Draft->value,
                'product_id' => $product->id,
                'inspection_spec_id' => $spec->id,
                'entity_type' => isset($data['entity_type']) ? InspectionEntityType::from((string) $data['entity_type'])->value : null,
                'entity_id' => isset($data['entity_id']) ? (int) $data['entity_id'] : null,
                'batch_quantity' => $batchQty,
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
            throw new RuntimeException('Inspection is already finalised.');
        }

        return DB::transaction(function () use ($inspection, $rows, $by) {
            $measurements = InspectionMeasurement::query()
                ->where('inspection_id', $inspection->id)
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
                ->where('inspection_id', $inspection->id)
                ->where('is_pass', false)
                ->count();

            $inspection->forceFill([
                'defect_count' => $defects,
                'status' => InspectionStatus::InProgress->value,
                'inspector_id' => $inspection->inspector_id ?? $by->id,
            ])->save();

            return $this->show($inspection);
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
            throw new RuntimeException('Inspection is already finalised.');
        }

        return DB::transaction(function () use ($inspection, $by) {
            $rows = InspectionMeasurement::query()
                ->where('inspection_id', $inspection->id)
                ->lockForUpdate()
                ->get();

            $unresolved = $rows->whereNull('is_pass')->count();
            if ($unresolved > 0) {
                throw new RuntimeException("Cannot complete: {$unresolved} measurement(s) have no pass/fail recorded.");
            }

            $criticalFail = $rows->contains(fn (InspectionMeasurement $r) => $r->is_critical && $r->is_pass === false);
            $defects = $rows->where('is_pass', false)->count();
            $accept = (int) $inspection->accept_count;

            $passed = ! $criticalFail && $defects <= $accept;

            $inspection->forceFill([
                'status' => $passed ? InspectionStatus::Passed->value : InspectionStatus::Failed->value,
                'defect_count' => $defects,
                'completed_at' => now(),
                'inspector_id' => $inspection->inspector_id ?? $by->id,
            ])->save();

            // Sprint 7 Task 61: auto-open an NCR when the inspection failed.
            // Wrapped in afterCommit so the NCR row is visible to listeners /
            // dashboards as soon as the inspection commit lands.
            if (! $passed) {
                DB::afterCommit(function () use ($inspection, $by) {
                    try {
                        app(NcrService::class)->openFromInspectionFailure(
                            $inspection->fresh(['measurements']),
                            $by,
                        );
                    } catch (\Throwable $e) {
                        // Auto-opening the NCR is non-fatal for the inspection,
                        // but a silent miss is an IATF traceability gap — log it.
                        Log::warning(
                            "InspectionService: failed to auto-open NCR for inspection {$inspection->id}: {$e->getMessage()}",
                            ['inspection_id' => $inspection->id, 'exception' => $e::class]
                        );
                    }
                    // Series C — Task C1/C2. Domain event for chain listeners.
                    event(new InspectionFailed($inspection->fresh()));
                });
            } else {
                // Series C — Task C1/C2. Inspection passed → drive the
                // outgoing-delivery / incoming-bill cascades.
                DB::afterCommit(fn () => event(new InspectionPassed($inspection->fresh()))
                );
            }

            return $this->show($inspection);
        });
    }

    public function cancel(Inspection $inspection, ?string $reason, User $by): Inspection
    {
        if ($inspection->status->isTerminal()) {
            throw new RuntimeException('Inspection is already finalised.');
        }
        $inspection->forceFill([
            'status' => InspectionStatus::Cancelled->value,
            'completed_at' => now(),
            'notes' => trim(($inspection->notes ? $inspection->notes."\n" : '').'[cancelled] '.($reason ?: 'no reason given').' — by user#'.$by->id),
        ])->save();

        return $this->show($inspection);
    }
}
