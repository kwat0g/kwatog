<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Dashboard\Enums\KpiDirection;
use App\Modules\Dashboard\Enums\KpiUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KpiDefinition extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'code',
        'name',
        'module',
        'unit',
        'direction',
        'target_value',
        'warning_threshold',
        'calculation_method',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'unit' => KpiUnit::class,
        'direction' => KpiDirection::class,
        'target_value' => 'decimal:4',
        'warning_threshold' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────

    public function snapshots(): HasMany
    {
        return $this->hasMany(KpiSnapshot::class, 'definition_id');
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
