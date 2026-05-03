<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
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
        'item_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'stock_movement_id',
        'created_at',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_cost'  => 'decimal:2',
        'total_cost' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'work_order_id');
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
