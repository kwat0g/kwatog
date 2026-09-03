<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionSchedule extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'work_order_id', 'machine_id', 'mold_id',
        'scheduled_start', 'scheduled_end', 'priority_order',
        'status', 'is_confirmed', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'status'          => ProductionScheduleStatus::class,
        'scheduled_start' => 'datetime',
        'scheduled_end'   => 'datetime',
        'priority_order'  => 'integer',
        'is_confirmed'    => 'boolean',
        'confirmed_at'    => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function mold(): BelongsTo
    {
        return $this->belongsTo(Mold::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * M050 — the plan of a started work order is an operational record.
     *
     * Eloquent-level silent guard: a stray ->update()/->delete() on a schedule
     * whose work order is running (or paused) is cancelled without an error,
     * so a UI bug cannot rewrite the window mid-run. Raw SQL is stopped by
     * the production_schedules_immutable_guard DB trigger (0479), which
     * raises instead of silently ignoring.
     */
    protected static function booted(): void
    {
        static::updating(static function (ProductionSchedule $row): bool {
            return ! self::belongsToStartedWorkOrder($row);
        });
        static::deleting(static function (ProductionSchedule $row): bool {
            return ! self::belongsToStartedWorkOrder($row);
        });
    }

    private static function belongsToStartedWorkOrder(ProductionSchedule $row): bool
    {
        return WorkOrder::query()
            ->whereKey($row->work_order_id)
            ->whereIn('status', [
                WorkOrderStatus::InProgress->value,
                WorkOrderStatus::Paused->value,
            ])
            ->exists();
    }
}
