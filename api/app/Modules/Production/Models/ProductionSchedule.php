<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionSchedule extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

    protected $fillable = [
        'work_order_id', 'machine_id', 'mold_id',
        'scheduled_start', 'scheduled_end', 'priority_order',
        'status', 'is_confirmed', 'confirmed_by', 'confirmed_at', 'reserves_capacity', 'rework_ncr_id',
    ];

    protected $casts = [
        'status' => ProductionScheduleStatus::class,
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'priority_order' => 'integer',
        'is_confirmed' => 'boolean', 'reserves_capacity' => 'boolean',
        'confirmed_at' => 'datetime',
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
     * Started production and repair timing rejects updates and deletion.
     * Explicit reservation release preserves the original plan. Raw SQL is stopped by
     * the production_schedules_immutable_guard DB trigger (0479), which
     * raises instead of silently ignoring.
     */
    protected static function booted(): void
    {
        static::updating(static function (ProductionSchedule $row): bool {
            // Approval versions must change even when two reviews occur in the same second.
            // Legacy schedule timestamps have second precision; avoid changing their schema.
            if ($row->updated_at && $row->getRawOriginal('updated_at')) {
                $row->updated_at = $row->updated_at->max($row->asDateTime($row->getRawOriginal('updated_at'))->addSecond());
            }
            if (self::belongsToStartedWorkOrder($row)) {
                if ($row->isDirty('reserves_capacity') && ! $row->reserves_capacity
                    && array_diff(array_keys($row->getDirty()), ['reserves_capacity', 'updated_at']) === []) {
                    return true;
                }
                throw new BusinessRuleException('Started schedule timing is immutable. Release resources or review a remaining production slot.');
            }

            return true;
        });
        static::deleting(static function (ProductionSchedule $row): bool {
            if (self::belongsToStartedWorkOrder($row)) {
                throw new BusinessRuleException('Cannot delete a started production schedule.');
            }

            return true;
        });
    }

    private static function belongsToStartedWorkOrder(ProductionSchedule $row): bool
    {
        if (NonConformanceReport::whereIn('id', array_filter([$row->rework_ncr_id, $row->getOriginal('rework_ncr_id')]))->whereNotNull('rework_started_at')->exists()) {
            return true;
        }

        return WorkOrder::query()
            ->whereIn('id', array_filter([$row->work_order_id, $row->getOriginal('work_order_id')]))
            ->whereIn('status', [
                WorkOrderStatus::InProgress->value,
                WorkOrderStatus::Paused->value,
            ])
            ->exists();
    }
}
