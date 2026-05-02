<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThirteenthMonthAccrual extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'employee_id',
        'year',
        'total_basic_earned',
        'accrued_amount',
        'is_paid',
        'paid_date',
        'payroll_id',
    ];

    protected $casts = [
        'year'               => 'integer',
        'total_basic_earned' => 'decimal:2',
        'accrued_amount'     => 'decimal:2',
        'is_paid'            => 'boolean',
        'paid_date'          => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }
}
