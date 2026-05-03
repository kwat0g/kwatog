<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Quality\Enums\InspectionParameterType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 7 — Task 60. One measurement reading.
 *
 * Tolerance bounds are denormalised on creation so the row remains
 * meaningful even if the parent spec is later revised. evaluate()
 * delegates to the same logic used by InspectionSpecItem.
 */
class InspectionMeasurement extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'inspection_id', 'inspection_spec_item_id',
        'sample_index', 'parameter_name', 'parameter_type',
        'unit_of_measure', 'nominal_value',
        'tolerance_min', 'tolerance_max', 'measured_value',
        'is_critical', 'is_pass', 'notes',
    ];

    protected $casts = [
        'parameter_type' => InspectionParameterType::class,
        'sample_index'   => 'integer',
        'nominal_value'  => 'decimal:4',
        'tolerance_min'  => 'decimal:4',
        'tolerance_max'  => 'decimal:4',
        'measured_value' => 'decimal:4',
        'is_critical'    => 'boolean',
        'is_pass'        => 'boolean',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function specItem(): BelongsTo
    {
        return $this->belongsTo(InspectionSpecItem::class, 'inspection_spec_item_id');
    }

    /**
     * Auto-evaluate against this row's denormalised tolerance window.
     * Returns null when the parameter is non-numeric or measurement is missing.
     */
    public function evaluate(): ?bool
    {
        if ($this->measured_value === null) return null;
        if ($this->tolerance_min === null && $this->tolerance_max === null) return null;
        $val = (float) $this->measured_value;
        if ($this->tolerance_min !== null && $val < (float) $this->tolerance_min) return false;
        if ($this->tolerance_max !== null && $val > (float) $this->tolerance_max) return false;
        return true;
    }
}
