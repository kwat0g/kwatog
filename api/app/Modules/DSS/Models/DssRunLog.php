<?php

declare(strict_types=1);

namespace App\Modules\DSS\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Evidence row per detector run: duration, rows scanned, emitted, errors. */
class DssRunLog extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'dss_run_logs';

    protected $fillable = [
        'detector_id',
        'detector_version',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'rows_scanned',
        'emitted',
        'error',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'rows_scanned' => 'integer',
        'emitted' => 'integer',
    ];

    public function scopeForDetector($query, string $detectorId)
    {
        return $query->where('detector_id', $detectorId);
    }
}
