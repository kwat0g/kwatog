<?php

declare(strict_types=1);

namespace App\Modules\MRP\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\MRP\Enums\MachineStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Machine extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'machine_code', 'name', 'tonnage', 'machine_type',
        'operators_required', 'available_hours_per_day', 'status',
        'current_work_order_id',
    ];

    protected $casts = [
        'status'                  => MachineStatus::class,
        'tonnage'                 => 'integer',
        'operators_required'      => 'decimal:1',
        'available_hours_per_day' => 'decimal:1',
        'current_work_order_id'   => 'integer',
    ];

    public function compatibleMolds(): BelongsToMany
    {
        return $this->belongsToMany(Mold::class, 'mold_machine_compatibility');
    }

    public function scopeAvailable(Builder $q): Builder
    {
        return $q->where('status', MachineStatus::Idle->value);
    }

    public function getIsAvailableNowAttribute(): bool
    {
        return $this->status === MachineStatus::Idle;
    }
}
