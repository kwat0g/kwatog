<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeaveBalance extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'employee_id', 'leave_type_id', 'year',
        'total_credits', 'used', 'remaining',
    ];

    protected $casts = [
        'year'          => 'integer',
        'total_credits' => 'decimal:1',
        'used'          => 'decimal:1',
        'remaining'     => 'decimal:1',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
