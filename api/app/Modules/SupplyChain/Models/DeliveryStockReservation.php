<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\SupplyChain\Enums\DeliveryStockReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryStockReservation extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'reservation_batch_id', 'delivery_id', 'delivery_item_id', 'item_id', 'location_id',
        'lot_number', 'expiry_date', 'quantity', 'consumed_quantity', 'released_quantity',
        'status', 'stock_movement_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'consumed_quantity' => 'decimal:3',
        'released_quantity' => 'decimal:3',
        'expiry_date' => 'date',
        'status' => DeliveryStockReservationStatus::class,
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DeliveryStockReservationBatch::class, 'reservation_batch_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function deliveryItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function remainingQuantity(): string
    {
        return bcsub(
            bcsub((string) $this->quantity, (string) $this->consumed_quantity, 3),
            (string) $this->released_quantity,
            3,
        );
    }
}
