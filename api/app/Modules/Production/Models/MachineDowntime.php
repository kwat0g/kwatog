<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasHashId;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineDowntime extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'machine_id', 'work_order_id', 'start_time', 'end_time',
        'duration_minutes', 'category', 'description', 'maintenance_order_id',
    ];

    protected $casts = [
        'category'         => MachineDowntimeCategory::class,
        'start_time'       => 'datetime',
        'end_time'         => 'datetime',
        'duration_minutes' => 'integer',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
