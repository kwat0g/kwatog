<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderResponseItem extends Model
{
    use HasAuditLog, HasHashId;

    protected $fillable = [
        'sales_order_response_id', 'sales_order_item_id',
        'proposed_quantity', 'proposed_unit_price', 'reason',
    ];

    protected $casts = [
        'proposed_quantity'   => 'decimal:2',
        'proposed_unit_price' => 'decimal:2',
    ];

    public function response(): BelongsTo
    {
        return $this->belongsTo(SalesOrderResponse::class, 'sales_order_response_id');
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }
}
