<?php

declare(strict_types=1);

namespace App\Modules\B2B\Models;

use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierShipmentItem extends Model
{
    protected $fillable = ['purchase_order_item_id', 'quantity_shipped'];

    protected $casts = ['quantity_shipped' => 'decimal:2'];

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
