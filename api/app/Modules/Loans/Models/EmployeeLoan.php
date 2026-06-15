<?php

declare(strict_types=1);

namespace App\Modules\Loans\Models;

use App\Common\Traits\HasApprovalWorkflow;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Enums\LoanType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeLoan extends Model
{
    use HasFactory, HasHashId, HasAuditLog, HasApprovalWorkflow;

    protected $fillable = [
        'loan_no', 'employee_id', 'loan_type',
        'principal', 'interest_rate', 'monthly_amortization',
        'total_paid', 'balance',
        'start_date', 'end_date',
        'pay_periods_total', 'pay_periods_remaining',
        'approval_chain_size', 'purpose',
        'is_final_pay_deduction',
    ];

    protected $casts = [
        'principal'              => 'decimal:2',
        'interest_rate'          => 'decimal:2',
        'monthly_amortization'   => 'decimal:2',
        'total_paid'             => 'decimal:2',
        'balance'                => 'decimal:2',
        'start_date'             => 'date',
        'end_date'               => 'date',
        'pay_periods_total'      => 'integer',
        'pay_periods_remaining'  => 'integer',
        'approval_chain_size'    => 'integer',
        'status'                 => LoanStatus::class,
        'loan_type'              => LoanType::class,
        'is_final_pay_deduction' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class, 'loan_id')->orderByDesc('payment_date');
    }

    /**
     * ApprovalService hook — the submitter is the employee's user account,
     * not the employee_id column itself.
     */
    public function approvalSubmitterId(): ?int
    {
        // users.employee_id (HasOne from Employee), not employees.user_id.
        $userId = \App\Modules\Auth\Models\User::query()
            ->where('employee_id', $this->employee_id)
            ->value('id');
        return $userId !== null ? (int) $userId : null;
    }
}
