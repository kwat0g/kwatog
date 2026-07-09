<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\PayrollAdjustment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REC-10 — per-employee record of a year-end leave disposition.
 * See migration 0264 for the rationale (single source of truth for
 * convert/carry/forfeit, consumed by ResetLeaveBalancesForYear + payroll).
 */
class YearEndLeaveDisposition extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'employee_id', 'leave_type_id', 'year',
        'days_converted', 'days_carried', 'days_forfeited',
        'cash_value', 'payroll_adjustment_id', 'processed_at',
    ];

    protected $casts = [
        'year'           => 'integer',
        'days_converted' => 'decimal:1',
        'days_carried'   => 'decimal:1',
        'days_forfeited' => 'decimal:1',
        'cash_value'     => 'decimal:2',
        'processed_at'   => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function payrollAdjustment(): BelongsTo
    {
        return $this->belongsTo(PayrollAdjustment::class);
    }
}
