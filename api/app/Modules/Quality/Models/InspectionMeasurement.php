<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Traits\HasAuditLog;
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
    use HasAuditLog, HasFactory, HasHashId;

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if ($row->isDirty('acceptance_criterion') || ($row->getOriginal('acceptance_criterion') !== null
                && $row->isDirty(['inspection_id', 'inspection_spec_item_id', 'sample_index', 'parameter_name', 'parameter_type',
                    'is_critical', 'nominal_value', 'tolerance_min', 'tolerance_max', 'unit_of_measure']))) {
                throw new BusinessRuleException('Acceptance criteria are pinned. Create a replacement inspection instead.');
            }
        });
    }

    protected $fillable = [
        'acceptance_criterion',
        'inspection_id', 'inspection_spec_item_id',
        'sample_index', 'parameter_name', 'parameter_type',
        'unit_of_measure', 'nominal_value',
        'tolerance_min', 'tolerance_max', 'measured_value',
        'is_critical', 'is_pass', 'notes',
    ];

    protected $casts = [
        'parameter_type' => InspectionParameterType::class,
        'sample_index' => 'integer',
        'nominal_value' => 'decimal:4',
        'tolerance_min' => 'decimal:4',
        'tolerance_max' => 'decimal:4',
        'measured_value' => 'decimal:4',
        'is_critical' => 'boolean',
        'is_pass' => 'boolean',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function specItem(): BelongsTo
    {
        return $this->belongsTo(InspectionSpecItem::class, 'inspection_spec_item_id')->withTrashed();
    }

    /** One checklist decision per specification parameter, independent of sampled readings. */
    public function checklistKey(): string
    {
        if ($this->inspection_spec_item_id !== null) {
            return 'spec:'.$this->inspection_spec_item_id;
        }

        return 'parameter:'.hash('sha256', json_encode([
            $this->parameter_name, $this->parameter_type, $this->unit_of_measure,
            $this->nominal_value, $this->tolerance_min, $this->tolerance_max, $this->is_critical,
        ], JSON_THROW_ON_ERROR));
    }

    public function hasTolerance(): bool
    {
        return $this->tolerance_min !== null || $this->tolerance_max !== null;
    }

    public function requiresSizeReading(): bool
    {
        return $this->parameter_type === InspectionParameterType::Dimensional && $this->hasTolerance();
    }

    /**
     * Auto-evaluate against this row's denormalised tolerance window.
     * Returns null when the parameter is non-numeric or measurement is missing.
     */
    public function evaluate(): ?bool
    {
        if ($this->measured_value === null) {
            return null;
        }
        if (! $this->hasTolerance()) {
            return null;
        }
        $val = (float) $this->measured_value;
        if ($this->tolerance_min !== null && $val < (float) $this->tolerance_min) {
            return false;
        }
        if ($this->tolerance_max !== null && $val > (float) $this->tolerance_max) {
            return false;
        }

        return true;
    }
}
