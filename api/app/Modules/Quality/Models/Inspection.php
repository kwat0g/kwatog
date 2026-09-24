<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'inspection_number', 'stage', 'status', 'inspection_mode',
        'proposed_result', 'reviewed_by', 'reviewed_at', 'review_remarks',
        'product_id', 'item_id', 'inspection_spec_id',
        'inspection_spec_revision_id',
        'item_quality_plan_id', 'entity_type', 'entity_id', 'work_order_output_id', 'grn_item_id',
        'batch_quantity', 'accepted_quantity', 'sample_size',
        'aql_code', 'accept_count', 'reject_count', 'defect_count',
        'inspector_id', 'calibration_record_id', 'started_at', 'completed_at', 'notes',
    ];

    protected $casts = [
        'stage' => InspectionStage::class,
        'status' => InspectionStatus::class,
        'proposed_result' => InspectionOutcome::class,
        'entity_type' => InspectionEntityType::class,
        'inspection_mode' => InspectionMode::class,
        'batch_quantity' => 'integer',
        'accepted_quantity' => 'integer',
        'sample_size' => 'integer',
        'accept_count' => 'integer',
        'reject_count' => 'integer',
        'defect_count' => 'integer',
        'sample_defect_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

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

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function requiresMakerChecker(): bool
    {
        if ($this->stage === InspectionStage::Incoming) {
            return $this->entity_type === InspectionEntityType::Grn;
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
            && (int) $this->inspector_id !== (int) $this->reviewed_by;
    }

    public function calibrationRecord(): BelongsTo
    {
        return $this->belongsTo(CalibrationRecord::class, 'calibration_record_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(InspectionMeasurement::class)->orderBy('sample_index')->orderBy('id');
    }

    public function scopeStage(Builder $q, InspectionStage|string $stage): Builder
    {
        return $q->where('stage', $stage instanceof InspectionStage ? $stage->value : $stage);
    }

    public function scopeStatus(Builder $q, InspectionStatus|string $status): Builder
    {
        return $q->where('status', $status instanceof InspectionStatus ? $status->value : $status);
    }
}
