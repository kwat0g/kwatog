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
use App\Modules\Production\Models\MachineDowntime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sprint 8 — Task 69. */
class MaintenanceWorkOrder extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

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
        'pre_maintenance_status',
        'completed_at',
        'downtime_minutes',
        'cost',
        'verification_required',
        'diagnosis',
        'repair_action',
        'functional_test_result',
        'repair_submitted_by',
        'repair_submitted_at',
        'parts_reconciled_by',
        'parts_reconciled_at',
        'verified_by',
        'verified_at',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'maintainable_type' => MaintainableType::class,
        'type' => MaintenanceWorkOrderType::class,
        'priority' => MaintenancePriority::class,
        'status' => MaintenanceWorkOrderStatus::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'downtime_minutes' => 'integer',
        'cost' => 'decimal:2',
        'verification_required' => 'boolean',
        'repair_submitted_at' => 'datetime',
        'parts_reconciled_at' => 'datetime',
        'verified_at' => 'datetime',
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

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repair_submitted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function partsReconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parts_reconciled_by');
    }

    public function machineTarget(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'maintainable_id')->withTrashed();
    }

    public function moldTarget(): BelongsTo
    {
        return $this->belongsTo(Mold::class, 'maintainable_id')->withTrashed();
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class, 'work_order_id')->orderBy('created_at')->orderBy('id');
    }

    public function partRequests(): HasMany
    {
        return $this->hasMany(MaintenancePartRequest::class, 'work_order_id');
    }

    public function spareParts(): HasMany
    {
        return $this->hasMany(SparePartUsage::class, 'work_order_id');
    }

    public function machineDowntimes(): HasMany
    {
        return $this->hasMany(MachineDowntime::class, 'maintenance_order_id');
    }

    public function maintainable(): Machine|Mold|null
    {
        return match ($this->maintainable_type) {
            MaintainableType::Machine => $this->machineTarget()->first(),
            MaintainableType::Mold => $this->moldTarget()->first(),
            default => null,
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
