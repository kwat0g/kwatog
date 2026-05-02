<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Common\Traits\HasApprovalWorkflow;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use HasFactory, HasHashId, HasAuditLog, HasApprovalWorkflow;

    protected $fillable = [
        'leave_request_no', 'employee_id', 'leave_type_id',
        'start_date', 'end_date', 'days', 'reason', 'document_path',
        'status', 'dept_approver_id', 'dept_approved_at',
        'hr_approver_id', 'hr_approved_at', 'rejection_reason',
    ];

    protected $casts = [
        'start_date'        => 'date',
        'end_date'          => 'date',
        'days'              => 'decimal:1',
        'status'            => LeaveRequestStatus::class,
        'dept_approved_at'  => 'datetime',
        'hr_approved_at'    => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function deptApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dept_approver_id');
    }

    public function hrApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_approver_id');
    }
}
