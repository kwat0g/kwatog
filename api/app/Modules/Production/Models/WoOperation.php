<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WoOperationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WoOperation extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'work_order_id',
        'routing_operation_id',
        'sequence',
        'operation_name',
        'machine_id',
        'mold_id',
        'operator_id',
        'status',
        'planned_start',
        'planned_end',
        'actual_start',
        'actual_end',
        'setup_start',
        'setup_end',
        'qty_planned',
        'qty_completed',
        'qty_scrapped',
        'scrap_reason',
        'downtime_minutes',
        'notes',
    ];

    protected $casts = [
        'status'           => WoOperationStatus::class,
        'planned_start'    => 'datetime',
        'planned_end'      => 'datetime',
        'actual_start'     => 'datetime',
        'actual_end'       => 'datetime',
        'setup_start'      => 'datetime',
        'setup_end'        => 'datetime',
        'qty_planned'      => 'decimal:4',
        'qty_completed'    => 'decimal:4',
        'qty_scrapped'     => 'decimal:4',
        'downtime_minutes' => 'decimal:2',
        'sequence'         => 'integer',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function routingOperation(): BelongsTo
    {
        return $this->belongsTo(RoutingOperation::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function mold(): BelongsTo
    {
        return $this->belongsTo(Mold::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'operator_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ProductionLog::class)->orderByDesc('recorded_at');
    }
}
