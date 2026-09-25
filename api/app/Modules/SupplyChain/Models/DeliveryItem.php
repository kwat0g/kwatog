<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryItem extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'delivery_id', 'sales_order_item_id', 'inspection_id',
        'stock_movement_id',
        'quantity', 'customer_received_quantity', 'unit_price',
    ];

    protected $casts = [
        'quantity'   => 'decimal:3',
        'customer_received_quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /** Canonical movements, including dispatches split across warehouse bins. */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'reference_id')
            ->where('reference_type', 'delivery_item')
            ->where('movement_type', 'delivery');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
