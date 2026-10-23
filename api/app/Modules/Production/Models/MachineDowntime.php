<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineDowntime extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

    protected $fillable = [
        'machine_id', 'work_order_id', 'start_time', 'end_time',
        'duration_minutes', 'category', 'description', 'maintenance_order_id',
    ];

    protected $casts = [
        'category' => MachineDowntimeCategory::class,
        'start_time' => 'datetime',
        'end_time' => 'datetime',
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

    public function maintenanceOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'maintenance_order_id');
    }

    /**
     * Close this open row and stamp its duration. The ONE place the
     * end_time/duration pair is written, so timings cannot drift per caller.
     */
    public function closeAt(?\DateTimeInterface $end = null): void
    {
        $end ??= now();
        $this->update([
            'end_time' => $end,
            'duration_minutes' => (int) max(0, $this->start_time->diffInMinutes($end, true)),
        ]);
    }
}
