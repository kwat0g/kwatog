<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeRequest extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected static function newFactory(): Factory
    {
        return \Database\Factories\Modules\Attendance\Models\OvertimeRequestFactory::new();
    }

    protected $fillable = [
        'employee_id', 'date', 'hours_requested', 'reason',
        'status', 'approved_by', 'approved_at', 'rejection_reason',
        'is_auto_detected',
    ];

    protected $casts = [
        'date'             => 'date',
        'hours_requested'  => 'decimal:1',
        'status'           => OvertimeStatus::class,
        'approved_at'      => 'datetime',
        'is_auto_detected' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
