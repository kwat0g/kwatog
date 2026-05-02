<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'employee_id', 'date', 'shift_id',
        'time_in', 'time_out',
        'regular_hours', 'overtime_hours', 'night_diff_hours',
        'tardiness_minutes', 'undertime_minutes',
        'holiday_type', 'is_rest_day', 'day_type_rate',
        'status', 'is_manual_entry', 'remarks',
    ];

    protected $casts = [
        'date'                => 'date',
        'time_in'             => 'datetime',
        'time_out'            => 'datetime',
        'regular_hours'       => 'decimal:2',
        'overtime_hours'      => 'decimal:2',
        'night_diff_hours'    => 'decimal:2',
        'tardiness_minutes'   => 'integer',
        'undertime_minutes'   => 'integer',
        'is_rest_day'         => 'boolean',
        'day_type_rate'       => 'decimal:2',
        'status'              => AttendanceStatus::class,
        'is_manual_entry'     => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
