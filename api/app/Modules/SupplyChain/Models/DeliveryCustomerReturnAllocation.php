<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryCustomerReturnAllocation extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'return_request_item_id', 'source_stock_movement_id', 'stock_movement_id', 'quantity', 'total_cost',
    ];

    protected $casts = ['quantity' => 'decimal:3', 'total_cost' => 'decimal:2'];

    public function returnRequestItem(): BelongsTo
    {
        return $this->belongsTo(ReturnRequestItem::class);
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'source_stock_movement_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
