<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollAnomalyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollAnomalyFlag extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'payroll_id', 'payroll_period_id', 'employee_id',
        'flag_type', 'details',
        'is_resolved', 'resolved_by', 'resolved_at', 'resolution_remarks',
    ];

    protected $casts = [
        'flag_type'   => PayrollAnomalyType::class,
        'details'     => 'array',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
