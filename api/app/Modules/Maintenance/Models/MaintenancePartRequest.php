<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenancePartRequest extends Model
{
    use HasHashId;

    protected $fillable = ['work_order_id', 'work_log_id', 'item_id', 'location_id', 'quantity', 'operation_key', 'status',
        'requested_by', 'withdrawn_by', 'withdrawn_at', 'handed_over_at'];

    protected $casts = ['quantity' => 'decimal:3', 'handed_over_at' => 'datetime', 'withdrawn_at' => 'datetime'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'work_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }
}
