<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeShiftAssignment extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

    public $timestamps = false;

    protected $fillable = ['employee_id', 'shift_id', 'effective_date', 'end_date', 'working_days', 'created_at'];

    protected $casts = [
        'working_days' => 'array',
        'effective_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
