<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderOutput extends Model
{
    use HasFactory, HasHashId;

    public $timestamps = false;

    protected $fillable = [
        'work_order_id', 'recorded_by', 'recorded_at',
        'good_count', 'reject_count', 'shift', 'batch_code', 'remarks',
    ];

    protected $casts = [
        'recorded_at'  => 'datetime',
        'good_count'   => 'integer',
        'reject_count' => 'integer',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function defects(): HasMany
    {
        return $this->hasMany(WorkOrderDefect::class, 'output_id');
    }

    public function getTotalCountAttribute(): int
    {
        return (int) $this->good_count + (int) $this->reject_count;
    }
}
