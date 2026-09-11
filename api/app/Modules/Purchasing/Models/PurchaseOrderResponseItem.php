<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderResponseItem extends Model
{
    use HasAuditLog, HasHashId;

    protected $fillable = [
        'purchase_order_response_id', 'purchase_order_item_id',
        'proposed_quantity', 'proposed_unit_price', 'reason',
    ];

    protected $casts = [
        'proposed_quantity'    => 'decimal:2',
        'proposed_unit_price'  => 'decimal:2',
    ];

    public function response(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderResponse::class, 'purchase_order_response_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
