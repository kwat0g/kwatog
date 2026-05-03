<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenancePriority;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderType;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sprint 8 — Task 69. */
class MaintenanceWorkOrder extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $table = 'maintenance_work_orders';

    protected $fillable = [
        'mwo_number',
        'maintainable_type',
        'maintainable_id',
        'schedule_id',
        'type',
        'priority',
        'description',
        'assigned_to',
        'status',
        'started_at',
        'completed_at',
        'downtime_minutes',
        'cost',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'maintainable_type' => MaintainableType::class,
        'type'              => MaintenanceWorkOrderType::class,
        'priority'          => MaintenancePriority::class,
        'status'            => MaintenanceWorkOrderStatus::class,
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
        'downtime_minutes'  => 'integer',
        'cost'              => 'decimal:2',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'schedule_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class, 'work_order_id')->orderBy('created_at');
    }

    public function spareParts(): HasMany
    {
        return $this->hasMany(SparePartUsage::class, 'work_order_id');
    }

    public function maintainable(): Machine|Mold|null
    {
        return match ($this->maintainable_type) {
            MaintainableType::Machine => Machine::find($this->maintainable_id),
            MaintainableType::Mold    => Mold::find($this->maintainable_id),
            default                   => null,
        };
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [
            MaintenanceWorkOrderStatus::Open->value,
            MaintenanceWorkOrderStatus::Assigned->value,
            MaintenanceWorkOrderStatus::InProgress->value,
        ]);
    }
}
