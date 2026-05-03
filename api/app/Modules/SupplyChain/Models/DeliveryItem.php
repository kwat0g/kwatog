<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryItem extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'delivery_id', 'sales_order_item_id', 'inspection_id',
        'quantity', 'unit_price',
    ];

    protected $casts = [
        'quantity'   => 'decimal:3',
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
}
