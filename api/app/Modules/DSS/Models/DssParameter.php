<?php

declare(strict_types=1);

namespace App\Modules\DSS\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-detector (or `global`) tunable, JSON-valued. Change history comes from
 * HasAuditLog — parameter governance (who changed what, when) rides the same
 * append-only trail as everything else. No separate history table.
 */
class DssParameter extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

    protected $table = 'dss_parameters';

    protected $fillable = [
        'detector_id',
        'key',
        'value',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForDetector($query, string $detectorId)
    {
        return $query->where('detector_id', $detectorId);
    }
}
