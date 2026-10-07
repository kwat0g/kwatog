<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePunch extends Model
{
    use HasAuditLog, HasHashId;

    protected $fillable = [
        'employee_id', 'attendance_id', 'direction', 'punched_at',
        'latitude', 'longitude', 'accuracy_m', 'distance_m', 'ip', 'user_agent',
    ];

    protected $casts = [
        'direction' => PunchDirection::class,
        'punched_at' => 'datetime',
        'latitude' => 'decimal:6',
        'longitude' => 'decimal:6',
        'accuracy_m' => 'decimal:2',
        'distance_m' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * Mask raw GPS coordinates in the audit trail. Raw latitude/longitude are
     * purged after 90 days; they should not be visible in audit_logs to anyone
     * but HR. Here we mask them as '***', and only audit logs with permission
     * attendance.punch.audit can see the real values (which are null after the 90-day window).
     *
     * @return array<string, string>
     */
    public function auditAttributeSnapshot(): array
    {
        return [
            'latitude' => '***',
            'longitude' => '***',
        ];
    }
}
