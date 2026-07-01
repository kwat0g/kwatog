<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Dashboard\Enums\KpiStatus;
use App\Modules\Dashboard\Enums\KpiTrend;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiSnapshot extends Model
{
    use HasHashId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'definition_id',
        'period_year',
        'period_month',
        'actual_value',
        'target_value',
        'previous_value',
        'trend',
        'status',
        'breakdown',
        'computed_at',
    ];

    protected $casts = [
        'actual_value' => 'decimal:4',
        'target_value' => 'decimal:4',
        'previous_value' => 'decimal:4',
        'trend' => KpiTrend::class,
        'status' => KpiStatus::class,
        'breakdown' => 'array',
        'computed_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────

    public function definition(): BelongsTo
    {
        return $this->belongsTo(KpiDefinition::class, 'definition_id');
    }
}
