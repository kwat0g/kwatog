<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenanceScheduleInterval;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Sprint 8 — Task 69. Preventive maintenance schedule. */
class MaintenanceSchedule extends Model
{
    use HasFactory, SoftDeletes, HasHashId, HasAuditLog;

    protected $table = 'maintenance_schedules';

    protected $fillable = [
        'maintainable_type',
        'maintainable_id',
        'schedule_type',
        'description',
        'interval_type',
        'interval_value',
        'last_performed_at',
        'next_due_at',
        'is_active',
    ];

    protected $casts = [
        'maintainable_type' => MaintainableType::class,
        'interval_type'     => MaintenanceScheduleInterval::class,
        'interval_value'    => 'integer',
        'last_performed_at' => 'datetime',
        'next_due_at'       => 'datetime',
        'is_active'         => 'boolean',
    ];

    public function workOrders(): HasMany
    {
        return $this->hasMany(MaintenanceWorkOrder::class, 'schedule_id');
    }

    /** Resolve the polymorphic target as a real Eloquent model. */
    public function maintainable(): Machine|Mold|null
    {
        return match ($this->maintainable_type) {
            MaintainableType::Machine => Machine::find($this->maintainable_id),
            MaintainableType::Mold    => Mold::find($this->maintainable_id),
            default                   => null,
        };
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeDue(Builder $q): Builder
    {
        return $q->whereNotNull('next_due_at')->where('next_due_at', '<=', now());
    }
}
