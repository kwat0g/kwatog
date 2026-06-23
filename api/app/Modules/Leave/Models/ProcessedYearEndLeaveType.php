<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessedYearEndLeaveType extends Model
{
    protected $fillable = [
        'leave_type_id',
        'year',
        'processed_at',
        'processed_by',
        'employees_count',
        'days_converted',
        'days_forfeited',
    ];

    protected $casts = [
        'year'            => 'integer',
        'processed_at'    => 'datetime',
        'employees_count' => 'integer',
        'days_converted'  => 'decimal:1',
        'days_forfeited'  => 'decimal:1',
    ];

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
