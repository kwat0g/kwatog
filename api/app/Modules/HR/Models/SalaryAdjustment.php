<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\SalaryAdjustmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REC-03 — a pending, maker-checker-gated salary change. The requested pay is
 * applied to the employee row ONLY when the `salary_adjustment` approval chain
 * is fully approved. Direct employee edits can no longer change pay.
 *
 * `status` is intentionally excluded from $fillable (mass-assignment hardening);
 * the service transitions it via forceFill()->save().
 */
class SalaryAdjustment extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'employee_id',
        'from_basic_monthly_salary',
        'from_daily_rate',
        'to_basic_monthly_salary',
        'to_daily_rate',
        'effective_date',
        'reason',
        'requested_by',
        'applied_at',
    ];

    protected $casts = [
        'from_basic_monthly_salary' => 'decimal:2',
        'from_daily_rate'           => 'decimal:2',
        'to_basic_monthly_salary'   => 'decimal:2',
        'to_daily_rate'             => 'decimal:2',
        'effective_date'            => 'date',
        'applied_at'                => 'datetime',
        'status'                    => SalaryAdjustmentStatus::class,
    ];

    /** Feeds the ApprovalService self-approval guard (resolveSubmitterUserId). */
    public function approvalSubmitterId(): ?int
    {
        return $this->requested_by !== null ? (int) $this->requested_by : null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
