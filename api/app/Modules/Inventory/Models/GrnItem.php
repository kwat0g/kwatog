<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrnItem extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'grn_items';

    protected $fillable = [
        'goods_receipt_note_id', 'purchase_order_item_id', 'item_id',
        'location_id', 'quantity_received', 'quantity_accepted',
        'unit_cost', 'remarks',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:3',
        'quantity_accepted' => 'decimal:3',
        'unit_cost'         => 'decimal:4',
    ];

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class, 'goods_receipt_note_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }
}
