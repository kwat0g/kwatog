<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Enums\InspectionOutcome;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Sprint 7 — Task 60. Quality inspection root row.
 *
 * Polymorphically links to the entity it gates (entity_type +
 * entity_id). Sample size + accept/reject are baked in at creation
 * time so the inspector cannot retroactively re-roll the AQL plan.
 */
class Inspection extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

    protected static function booted(): void
    {
        static::updating(function (self $inspection): void {
            if ($inspection->getOriginal('counted_capture') !== null
                && $inspection->isDirty(['counted_capture', 'batch_quantity', 'sample_size', 'inspection_spec_id', 'inspection_spec_revision_id', 'work_order_output_id', 'grn_item_id', 'entity_type', 'entity_id', 'stage', 'product_id', 'item_id', 'item_quality_plan_id', 'return_request_item_id', 'wo_operation_id', 'source_quantity', 'source_uom_code', 'sampling_basis', 'capture_method'])) {
                throw new BusinessRuleException('Pinned counted inspection coverage and source cannot change.');
            }
            if (($inspection->isDirty(['startup_trial_required', 'entity_type', 'entity_id', 'stage', 'sample_size', 'wo_operation_id', 'work_order_output_id'])
                    || ($inspection->isDirty('status') && $inspection->status === InspectionStatus::Cancelled))
                && $inspection->trialOutput()->exists()) {
                throw new BusinessRuleException('Captured trial sample source and requirement are immutable; its inspection cannot be cancelled.');
            }
        });
        static::creating(function (self $inspection): void {
            if ($inspection->inspection_spec_revision_id || ! $inspection->inspection_spec_id) {
                return;
            }

            $spec = InspectionSpec::withTrashed()->find((int) $inspection->inspection_spec_id);
            if ($spec) {
                $inspection->inspection_spec_revision_id = $spec->ensureCurrentRevision()->id;
            }
        });
    }

    protected $fillable = [
        'counted_capture', 'coverage_confirmed_at',
        'startup_trial_required', 'capture_method', 'source_quantity', 'source_uom_code', 'sampling_basis', 'result_version', 'replaces_inspection_id',
        'inspection_number', 'stage', 'status', 'inspection_mode',
        'proposed_result', 'reviewed_by', 'reviewed_at', 'review_remarks',
        'product_id', 'item_id', 'inspection_spec_id',
        'inspection_spec_revision_id',
        'return_request_item_id', 'item_quality_plan_id', 'entity_type', 'entity_id', 'work_order_output_id', 'grn_item_id', 'wo_operation_id',
        'batch_quantity', 'accepted_quantity', 'sample_size',
        'aql_code', 'accept_count', 'reject_count', 'defect_count',
        'inspector_id', 'calibration_record_id', 'started_at', 'completed_at', 'notes',
    ];

    protected $casts = [
        'counted_capture' => 'array',
        'coverage_confirmed_at' => 'datetime',
        'startup_trial_required' => 'boolean',
        'source_quantity' => 'decimal:3', 'result_version' => 'integer',
        'stage' => InspectionStage::class,
        'status' => InspectionStatus::class,
        'proposed_result' => InspectionOutcome::class,
        'recorded_result' => InspectionOutcome::class,
        'affected_quantity' => 'integer',
        'entity_type' => InspectionEntityType::class,
        'inspection_mode' => InspectionMode::class,
        'batch_quantity' => 'integer',
        'accepted_quantity' => 'integer',
        'sample_size' => 'integer',
        'accept_count' => 'integer',
        'reject_count' => 'integer',
        'defect_count' => 'integer',
        'sample_defect_count' => 'integer',
        'inspected_quantity' => 'integer',
        'critical_defect' => 'boolean',
        'defects_removed_confirmed' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function usesChecklistCapture(): bool
    {
        return $this->capture_method === 'checklist';
    }

    public function usesFullPieceCapture(): bool
    {
        return ($this->counted_capture['workflow'] ?? null) === 'finish_first_v1'
            && (($this->counted_capture['version'] ?? null) === 2 || $this->usesConfirmedChecks());
    }

    public function usesConfirmedChecks(): bool
    {
        return ($this->counted_capture['version'] ?? null) === 3
            && ($this->counted_capture['workflow'] ?? null) === 'finish_first_v1'
            && ($this->counted_capture['evidence_method'] ?? null) === 'confirmed_checks';
    }

    public function isUntouchedFullPieceDraft(): bool
    {
        return $this->usesFullPieceCapture() && ! $this->usesConfirmedChecks()
            && $this->status === InspectionStatus::Draft && (int) $this->result_version === 0
            && ! $this->coverage_confirmed_at && ! $this->reviewed_at && ! $this->completed_at
            && ! $this->reviewed_by && ! $this->review_remarks && ! $this->calibration_record_id
            && ! $this->recorded_result && ! $this->proposed_result && ! $this->problem_description
            && (int) $this->accepted_quantity === 0 && (int) $this->sample_defect_count === 0
            && (int) $this->defect_count === 0 && (int) $this->affected_quantity === 0
            && ! $this->inspector_id && ! $this->inspected_quantity && ! $this->critical_defect
            && ! $this->resultAuthors()->exists() && ! $this->pieceReadings()->exists()
            && ! $this->defectivePieces()->exists() && ! $this->ncr()->exists()
            && ! $this->measurements()->where(function ($query): void {
                $query->whereNotNull('measured_value')->orWhereNotNull('is_pass')
                    ->orWhereNotNull('item_result')->orWhereNotNull('item_problem_description');
            })->exists();
    }

    public function isCountOnlyIncoming(): bool
    {
        return $this->stage === InspectionStage::Incoming
            && $this->entity_type === InspectionEntityType::Grn
            && $this->usesChecklistCapture()
            && $this->sampling_basis === 'piece'
            && $this->item_quality_plan_id === null
            && $this->aql_code === null
            && $this->sample_size === $this->batch_quantity;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(InspectionSpec::class, 'inspection_spec_id');
    }

    public function specRevision(): BelongsTo
    {
        return $this->belongsTo(InspectionSpecRevision::class, 'inspection_spec_revision_id');
    }

    public function qualityPlan(): BelongsTo
    {
        return $this->belongsTo(ItemQualityPlan::class, 'item_quality_plan_id');
    }

    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GrnItem::class, 'grn_item_id');
    }

    public function workOrderOutput(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOutput::class, 'work_order_output_id');
    }

    public function trialOutput(): HasOne
    {
        return $this->hasOne(WorkOrderOutput::class, 'trial_inspection_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function resultAuthors(): HasMany
    {
        return $this->hasMany(InspectionResultAuthor::class);
    }

    public function hasResultAuthor(?User $user): bool
    {
        return $this->hasResultAuthorId($user?->id);
    }

    public function hasResultAuthorId(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }

        if ($this->relationLoaded('resultAuthors')) {
            return $this->getRelation('resultAuthors')->contains(
                static fn (InspectionResultAuthor $author): bool => (int) $author->user_id === $userId,
            );
        }

        return $this->resultAuthors()->where('user_id', $userId)->exists();
    }

    public function requiresMakerChecker(): bool
    {
        if ($this->stage === InspectionStage::InProcess && $this->work_order_output_id) {
            $historicalOutput = $this->relationLoaded('workOrderOutput')
                ? $this->getRelation('workOrderOutput')?->production_started_at !== null
                : $this->workOrderOutput()->whereNotNull('production_started_at')->exists();
            if ($historicalOutput) {
                return true;
            }
        }
        if ($this->usesChecklistCapture() && $this->entity_type === InspectionEntityType::ReturnRequest) {
            return true;
        }
        if ($this->stage === InspectionStage::Incoming) {
            return $this->entity_type === InspectionEntityType::Grn;
        }

        if ($this->stage === InspectionStage::CustomerReturn
            && $this->entity_type === InspectionEntityType::ReturnRequest
            && $this->entity_id !== null) {
            // Only the new protected truck-return bridge reopens previously
            // consumed outgoing capacity. Its depot QC must therefore have an
            // independent reviewer; preserve ordinary RMA behavior.
            return ReturnRequest::query()
                ->whereKey((int) $this->entity_id)
                ->whereNotNull('delivery_attempt_outcome_id')
                ->exists();
        }

        return $this->stage === InspectionStage::Outgoing
            && ($this->work_order_output_id !== null
                || in_array($this->entity_type, [InspectionEntityType::WorkOrder, InspectionEntityType::Delivery], true));
    }

    public function isMakerChecked(): bool
    {
        return $this->inspector_id !== null
            && $this->reviewed_by !== null
            && $this->reviewed_at !== null
            && (int) $this->inspector_id !== (int) $this->reviewed_by
            && ! $this->hasResultAuthorId((int) $this->reviewed_by);
    }

    public function canConfirmReviewedSeparation(?User $user): bool
    {
        return $this->stage === InspectionStage::Outgoing
            && $this->entity_type === InspectionEntityType::WorkOrder
            && $this->work_order_output_id !== null
            && $this->usesFullPieceCapture() && $this->isMakerChecked()
            && $user?->is_active && $user->role?->slug === 'qc_inspector'
            && $user->hasPermission('quality.ncr.manage');
    }

    public function ncr(): HasOne
    {
        return $this->hasOne(NonConformanceReport::class)->latestOfMany();
    }

    public function processRecoveryQuantity(): int
    {
        if ($this->work_order_output_id && $this->stage === InspectionStage::InProcess && $this->workOrderOutput?->production_started_at) {
            return (int) $this->workOrderOutput->physical_count;
        }
        if ($this->startup_trial_required) {
            return (int) $this->trialOutput()->value('trial_quantity');
        }

        return $this->usesChecklistCapture()
            ? (int) ($this->inspected_quantity ?? $this->sample_size)
            : (int) $this->batch_quantity;
    }

    /** One release predicate for delivery options, reservation, dispatch and MRP. */
    public function canReleaseOutgoing(): bool
    {
        if ($this->stage !== InspectionStage::Outgoing || ! $this->work_order_output_id
            || ! $this->isMakerChecked() || $this->accepted_quantity <= 0) {
            return false;
        }
        $output = $this->workOrderOutput;
        if ($output?->trial_inspection_id !== null && $output?->trial_settled_at === null) {
            return false;
        }
        $workOrder = $output?->workOrder;
        if ($workOrder?->status?->value === 'cancelled') {
            return false;
        }
        if ($workOrder) {
            try {
                $workOrder->assertProcessQualityReleased();
            } catch (BusinessRuleException) {
                return false;
            }
        }
        $ncr = $this->ncr;
        if ($ncr?->status === NcrStatus::Cancelled) {
            $ncr = NonConformanceReport::query()->where('inspection_id', $this->id)->where('status', '!=', NcrStatus::Cancelled->value)->orderByDesc('id')->first();
        }
        if ($this->status === InspectionStatus::Passed && ($ncr === null || $ncr->status === NcrStatus::Cancelled)) {
            return true;
        }

        return in_array($this->status, [InspectionStatus::Passed, InspectionStatus::Failed], true) && $ncr !== null
            && $ncr->status !== NcrStatus::Cancelled
            && $ncr->sorting_approved_at !== null && $ncr->sorting_evidence_reference !== null
            && (int) $ncr->sorting_inspected_quantity === (int) ($ncr->recovery_batch_quantity ?? $this->batch_quantity)
            && (int) $ncr->mrb_accepted_quantity + (int) $ncr->previously_released_quantity === (int) $this->accepted_quantity
            && ($ncr->sorting_uses_reviewed_qc
                ? $this->usesFullPieceCapture() && $this->entity_type === InspectionEntityType::WorkOrder
                    && (int) $this->accepted_quantity <= max(0, (int) $this->batch_quantity - max((int) $this->sample_defect_count, (int) $this->defect_count))
                : $ncr->sorting_approved_by !== $ncr->sorting_performed_by
                    && ! $this->hasResultAuthorId((int) $ncr->sorting_approved_by)
                    && (int) $ncr->sorting_approved_by !== (int) $this->inspector_id);
    }

    /** A consumed or scrapped source lot is resolved even when no source pieces remain releasable. */
    public function hasResolvedOutgoingDisposition(): bool
    {
        return $this->stage === InspectionStage::Outgoing && $this->isMakerChecked()
            && in_array($this->status, [InspectionStatus::Passed, InspectionStatus::Failed], true)
            && $this->ncr?->status === NcrStatus::Closed
            && in_array($this->ncr->disposition?->value, ['scrap', 'rework'], true)
            && $this->ncr->sorting_approved_at !== null;
    }

    public function scopeOutgoingReleaseCandidates(Builder $query): Builder
    {
        return $query->where('stage', InspectionStage::Outgoing->value)
            ->whereNotNull('work_order_output_id')->whereNotNull('reviewed_at')->whereNotNull('reviewed_by')
            ->where('accepted_quantity', '>', 0)
            ->where(fn (Builder $q) => $q->where('status', InspectionStatus::Passed->value)
                ->orWhere(fn (Builder $q) => $q->where('status', InspectionStatus::Failed->value)
                    ->whereHas('ncr', fn (Builder $q) => $q->whereNotNull('sorting_approved_at'))));
    }

    public function calibrationRecord(): BelongsTo
    {
        return $this->belongsTo(CalibrationRecord::class, 'calibration_record_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(InspectionMeasurement::class)->orderBy('sample_index')->orderBy('id');
    }

    public function defectivePieces(): HasMany
    {
        return $this->hasMany(InspectionDefectivePiece::class)->orderBy('piece_number');
    }

    public function pieceReadings(): HasMany
    {
        return $this->hasMany(InspectionPieceReading::class)->orderBy('piece_number')->orderBy('requirement_id');
    }

    public function scopeStage(Builder $q, InspectionStage|string $stage): Builder
    {
        return $q->where('stage', $stage instanceof InspectionStage ? $stage->value : $stage);
    }

    public function scopeToInspect(Builder $query): Builder
    {
        return $query->whereIn('status', [InspectionStatus::Draft->value, InspectionStatus::InProgress->value]);
    }

    public function scopeStatus(Builder $q, InspectionStatus|string $status): Builder
    {
        return $q->where('status', $status instanceof InspectionStatus ? $status->value : $status);
    }
}
