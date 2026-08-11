<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payroll extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    public const EMAIL_PENDING = 'pending';
    public const EMAIL_QUEUED = 'queued';
    public const EMAIL_SENT = 'sent';
    public const EMAIL_FAILED = 'failed';

    protected static function newFactory(): \Database\Factories\PayrollFactory
    {
        return \Database\Factories\PayrollFactory::new();
    }

    protected $fillable = [
        'payroll_period_id', 'employee_id', 'pay_type',
        'days_worked',
        'basic_pay', 'overtime_pay', 'night_diff_pay', 'holiday_pay', 'gross_pay',
        'leave_pay',
        'tardiness_deduction', 'undertime_deduction',
        'sss_ee', 'sss_er',
        'philhealth_ee', 'philhealth_er',
        'pagibig_ee', 'pagibig_er',
        'withholding_tax',
        'loan_deductions', 'other_deductions', 'adjustment_amount',
        'total_deductions', 'net_pay',
        'error_message', 'computed_at',
        'payslip_emailed_at', 'payslip_email_status',
        'payslip_email_attempts', 'payslip_email_queued_at',
        'payslip_email_last_error',
    ];

    protected $casts = [
        'days_worked'       => 'decimal:1',
        'basic_pay'         => 'decimal:2',
        'leave_pay'         => 'decimal:2',
        'overtime_pay'      => 'decimal:2',
        'night_diff_pay'    => 'decimal:2',
        'holiday_pay'       => 'decimal:2',
        'tardiness_deduction' => 'decimal:2',
        'undertime_deduction' => 'decimal:2',
        'gross_pay'         => 'decimal:2',
        'sss_ee'            => 'decimal:2',
        'sss_er'            => 'decimal:2',
        'philhealth_ee'     => 'decimal:2',
        'philhealth_er'     => 'decimal:2',
        'pagibig_ee'        => 'decimal:2',
        'pagibig_er'        => 'decimal:2',
        'withholding_tax'   => 'decimal:2',
        'loan_deductions'   => 'decimal:2',
        'other_deductions'  => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'total_deductions'  => 'decimal:2',
        'net_pay'           => 'decimal:2',
        'computed_at'       => 'datetime',
        'payslip_emailed_at' => 'datetime',
        'payslip_email_attempts' => 'integer',
        'payslip_email_queued_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function deductionDetails(): HasMany
    {
        return $this->hasMany(PayrollDeductionDetail::class);
    }

    public function hasError(): bool
    {
        return $this->error_message !== null && $this->error_message !== '';
    }
}
