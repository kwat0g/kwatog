<?php

declare(strict_types=1);

namespace App\Modules\MRP\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use Database\Factories\MachineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Machine extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected static function newFactory(): Factory
    {
        return MachineFactory::new();
    }

    protected $fillable = [
        'machine_code', 'name', 'tonnage', 'machine_type',
        'operators_required', 'available_hours_per_day', 'status',
        'current_work_order_id',
        'running_hours_total',
        'running_hours_updated_at',
    ];

    protected $casts = [
        'status'                  => MachineStatus::class,
        'tonnage'                 => 'integer',
        'operators_required'      => 'decimal:1',
        'available_hours_per_day' => 'decimal:1',
        'current_work_order_id'   => 'integer',
        'running_hours_total'     => 'decimal:2',
        'running_hours_updated_at' => 'datetime',
    ];

    public function compatibleMolds(): BelongsToMany
    {
        return $this->belongsToMany(Mold::class, 'mold_machine_compatibility');
    }

    public function currentWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'current_work_order_id');
    }

    public function scopeAvailable(Builder $q): Builder
    {
        return $q->where('status', MachineStatus::Idle->value);
    }

    public function getIsAvailableNowAttribute(): bool
    {
        return $this->status === MachineStatus::Idle;
    }

    /**
     * M050 — a machine entering breakdown must release every promised
     * (pending/confirmed) window so the scheduler can replan that work
     * elsewhere. Rows belonging to a running/paused work order are left
     * alone: their plan is an operational record, and the pause flow handles
     * the running WO separately.
     */
    protected static function booted(): void
    {
        static::updating(static function (Machine $machine): void {
            $from = $machine->getOriginal('status');
            $fromValue = $from instanceof MachineStatus ? $from->value : (string) $from;
            $toValue = $machine->status instanceof MachineStatus
                ? $machine->status->value
                : (string) $machine->status;
            if ($fromValue !== MachineStatus::Breakdown->value
                && $toValue === MachineStatus::Breakdown->value) {
                ProductionSchedule::where('machine_id', $machine->id)
                    ->whereIn('status', [
                        ProductionScheduleStatus::Pending->value,
                        ProductionScheduleStatus::Confirmed->value,
                    ])
                    ->whereDoesntHave('workOrder', static fn ($q) => $q->whereIn('status', [
                        WorkOrderStatus::InProgress->value,
                        WorkOrderStatus::Paused->value,
                    ]))
                    ->update(['status' => ProductionScheduleStatus::Superseded->value]);
            }
        });
    }
}
