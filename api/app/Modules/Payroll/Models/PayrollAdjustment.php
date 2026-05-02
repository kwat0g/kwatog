<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Enums\PayrollAdjustmentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollAdjustment extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'payroll_period_id', 'employee_id', 'original_payroll_id',
        'type', 'amount', 'reason',
        'approved_by', 'status',
        'applied_at', 'applied_to_payroll_id',
    ];

    protected $casts = [
        'type'       => PayrollAdjustmentType::class,
        'status'     => PayrollAdjustmentStatus::class,
        'amount'     => 'decimal:2',
        'applied_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function originalPayroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class, 'original_payroll_id');
    }

    public function appliedToPayroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class, 'applied_to_payroll_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', PayrollAdjustmentStatus::Pending->value);
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('status', PayrollAdjustmentStatus::Approved->value);
    }
}
