<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OGAMI-011 — records each effective-dated salary change for an employee.
 * Used by PayrollCalculatorService to prorate basic pay when a raise lands
 * mid-period. When no rows exist for an employee, payroll falls back to the
 * employee's current basic_monthly_salary / daily_rate (legacy behaviour).
 */
class EmployeeSalaryHistory extends Model
{
    use HasHashId;

    protected $table = 'employee_salary_history';

    protected $fillable = [
        'employee_id',
        'basic_monthly_salary',
        'daily_rate',
        'effective_date',
        'created_by',
    ];

    protected $casts = [
        'basic_monthly_salary' => 'decimal:2',
        'daily_rate'           => 'decimal:2',
        'effective_date'       => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
