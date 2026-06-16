<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Quality\Enums\CalibrationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * OGAMI-016 — IATF 16949 calibration register entry for measuring equipment.
 */
class CalibrationRecord extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'equipment_code', 'name', 'location',
        'last_calibration_date', 'next_calibration_date',
        'frequency_days', 'status', 'responsible', 'remarks',
    ];

    protected $casts = [
        'last_calibration_date' => 'date',
        'next_calibration_date' => 'date',
        'frequency_days'        => 'integer',
        'status'                => CalibrationStatus::class,
    ];
}
