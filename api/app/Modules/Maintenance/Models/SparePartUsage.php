<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Maintenance\Models\MaintenanceLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sprint 8 — Task 69. Spare-part consumption tied to a maintenance WO. */
class SparePartUsage extends Model
{
    use HasFactory, HasHashId;

    public $timestamps = false;

    protected $table = 'spare_part_usage';

    protected $fillable = [
        'work_order_id',
        'work_log_id',
        'item_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'stock_movement_id',
        'operation_key',
        'issue_group_key',
        'returned_quantity',
        'returned_cost',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
        'returned_quantity' => 'decimal:3',
        'returned_cost' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'work_order_id');
    }

    public function workLog(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLog::class, 'work_log_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
