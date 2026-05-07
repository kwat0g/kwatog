<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Common\Enums\ExportFormat;
use App\Common\Enums\ExportFrequency;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Series E (Task E2) — a saved export schedule. Runner ticks every 5
 * minutes and dispatches the matching export class to email.
 */
class ScheduledExport extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'owner_id', 'name', 'module',
        'columns', 'filters', 'format',
        'frequency', 'day_of_week', 'day_of_month', 'time_of_day',
        'recipients',
        'last_run_at', 'next_run_at',
        'is_active',
    ];

    protected $casts = [
        'columns'      => 'array',
        'filters'      => 'array',
        'recipients'   => 'array',
        'frequency'    => ExportFrequency::class,
        'format'       => ExportFormat::class,
        'last_run_at'  => 'datetime',
        'next_run_at'  => 'datetime',
        'is_active'    => 'boolean',
        'day_of_week'  => 'integer',
        'day_of_month' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeDue($query, ?\DateTimeInterface $now = null)
    {
        $now ??= now();
        return $query->where('is_active', true)->where('next_run_at', '<=', $now);
    }
}
