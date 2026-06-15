<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Common\Traits\HasApprovalWorkflow;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use HasFactory, HasHashId, HasAuditLog, HasApprovalWorkflow;

    protected static function newFactory(): Factory
    {
        return \Database\Factories\Modules\Leave\Models\LeaveRequestFactory::new();
    }

    protected $fillable = [
        'leave_request_no', 'employee_id', 'leave_type_id',
        'start_date', 'end_date', 'days', 'half_day_period',
        'reason', 'document_path',
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

    /**
     * ApprovalService hook — the submitter is the employee's user account,
     * not the employee_id column itself.
     */
    public function approvalSubmitterId(): ?int
    {
        // Link is on users.employee_id (HasOne from Employee), not on employees.
        $userId = \App\Modules\Auth\Models\User::query()
            ->where('employee_id', $this->employee_id)
            ->value('id');
        return $userId !== null ? (int) $userId : null;
    }
}
