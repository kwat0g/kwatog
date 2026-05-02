<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id', 'item_id', 'purchase_request_item_id',
        'description', 'quantity', 'unit', 'unit_price', 'total',
        'quantity_received',
    ];

    protected $casts = [
        'quantity'          => 'decimal:2',
        'unit_price'        => 'decimal:2',
        'total'             => 'decimal:2',
        'quantity_received' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function getQuantityRemainingAttribute(): string
    {
        $diff = (float) $this->quantity - (float) $this->quantity_received;
        return number_format(max(0.0, $diff), 2, '.', '');
    }
}
