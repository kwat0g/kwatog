<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'wo_number', 'product_id', 'sales_order_id', 'sales_order_item_id',
        'mrp_plan_id', 'parent_wo_id', 'parent_ncr_id', 'machine_id', 'mold_id',
        'quantity_target', 'quantity_produced', 'quantity_good',
        'quantity_rejected', 'scrap_rate',
        'planned_start', 'planned_end', 'actual_start', 'actual_end',
        'status', 'pause_reason', 'priority', 'created_by',
    ];

    protected $casts = [
        'status'            => WorkOrderStatus::class,
        'planned_start'     => 'datetime',
        'planned_end'       => 'datetime',
        'actual_start'      => 'datetime',
        'actual_end'        => 'datetime',
        'quantity_target'   => 'decimal:0',
        'quantity_produced' => 'decimal:0',
        'quantity_good'     => 'decimal:0',
        'quantity_rejected' => 'decimal:0',
        'scrap_rate'        => 'decimal:2',
        'priority'          => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function mold(): BelongsTo
    {
        return $this->belongsTo(Mold::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_wo_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(WorkOrderOutput::class)->orderByDesc('recorded_at');
    }

    public function downtimes(): HasMany
    {
        return $this->hasMany(MachineDowntime::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ProductionSchedule::class);
    }

    public function scopeStatus(Builder $q, WorkOrderStatus|string $s): Builder
    {
        return $q->where('status', $s instanceof WorkOrderStatus ? $s->value : $s);
    }

    public function getProgressPercentageAttribute(): float
    {
        $target = (int) $this->quantity_target;
        if ($target <= 0) return 0.0;
        return round(min(100.0, ((int) $this->quantity_produced / $target) * 100), 1);
    }
}
