<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionEntityType;
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
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'inspection_number', 'stage', 'status',
        'product_id', 'inspection_spec_id',
        'entity_type', 'entity_id',
        'batch_quantity', 'sample_size',
        'aql_code', 'accept_count', 'reject_count', 'defect_count',
        'inspector_id', 'started_at', 'completed_at', 'notes',
    ];

    protected $casts = [
        'stage'          => InspectionStage::class,
        'status'         => InspectionStatus::class,
        'entity_type'    => InspectionEntityType::class,
        'batch_quantity' => 'integer',
        'sample_size'    => 'integer',
        'accept_count'   => 'integer',
        'reject_count'   => 'integer',
        'defect_count'   => 'integer',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(InspectionSpec::class, 'inspection_spec_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
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
