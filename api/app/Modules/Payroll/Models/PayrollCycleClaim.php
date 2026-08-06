<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row = "this employee has been paid for this pay cycle".
 *
 * The unique index on (employee_id, cycle_key) is the race-proof guard against
 * double payment across two scoped periods covering the same cutoff. See
 * migration 0439 for the full rationale.
 *
 * Deliberately NOT audited (HasAuditLog): a claim is bookkeeping derived from
 * the payroll row, which is itself audited, and a 200-employee run would
 * otherwise write 200 meaningless audit rows per compute.
 */
class PayrollCycleClaim extends Model
{
    use HasHashId;

    protected $table = 'payroll_cycle_claims';

    protected $fillable = [
        'employee_id',
        'payroll_id',
        'payroll_period_id',
        'cycle_key',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }
}
