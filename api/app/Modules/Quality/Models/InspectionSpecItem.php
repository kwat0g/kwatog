<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Quality\Enums\InspectionParameterType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sprint 7 — Task 59. Single inspection parameter row.
 *
 * Sprint 7 Task 60's InspectionService::create() reads the parent spec's
 * items to seed the measurement form. Pass/fail evaluation reads
 * tolerance_min/tolerance_max here; null tolerances mean the parameter
 * is non-numeric (visual checks).
 */
class InspectionSpecItem extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            if ($item->inspection_spec_revision_id || ! $item->inspection_spec_id) {
                return;
            }

            $spec = InspectionSpec::withTrashed()->find((int) $item->inspection_spec_id);
            if ($spec) {
                $item->inspection_spec_revision_id = $spec->ensureCurrentRevision()->id;
            }
        });
    }

    protected $fillable = [
        'inspection_spec_id', 'inspection_spec_revision_id', 'parameter_name', 'parameter_type',
        'unit_of_measure', 'nominal_value', 'tolerance_min', 'tolerance_max',
        'is_critical', 'sort_order', 'notes',
    ];

    protected $casts = [
        'parameter_type' => InspectionParameterType::class,
        'nominal_value'  => 'decimal:4',
        'tolerance_min'  => 'decimal:4',
        'tolerance_max'  => 'decimal:4',
        'is_critical'    => 'boolean',
        'sort_order'     => 'integer',
    ];

    public function spec(): BelongsTo
    {
        return $this->belongsTo(InspectionSpec::class, 'inspection_spec_id')->withTrashed();
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(InspectionSpecRevision::class, 'inspection_spec_revision_id');
    }

    /**
     * Evaluate a measurement against this spec item's tolerance window.
     * Returns true (pass), false (fail), or null when the parameter is
     * non-numeric (the inspector must record a manual pass/fail flag).
     */
    public function evaluate(?float $measurement): ?bool
    {
        if ($measurement === null) return null;
        if ($this->tolerance_min === null && $this->tolerance_max === null) return null;
        if ($this->tolerance_min !== null && $measurement < (float) $this->tolerance_min) return false;
        if ($this->tolerance_max !== null && $measurement > (float) $this->tolerance_max) return false;
        return true;
    }
}
