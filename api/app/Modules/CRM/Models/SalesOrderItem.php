<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    use HasFactory, HasHashId;

    public $timestamps = false;

    protected $fillable = [
        'sales_order_id', 'product_id', 'quantity', 'unit_price',
        'total', 'quantity_delivered', 'delivery_date',
    ];

    protected $casts = [
        'quantity'           => 'decimal:2',
        'unit_price'         => 'decimal:2',
        'total'              => 'decimal:2',
        'quantity_delivered' => 'decimal:2',
        'delivery_date'      => 'date',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Outstanding qty (target − delivered). */
    public function getRemainingQuantityAttribute(): string
    {
        $remaining = (float) $this->quantity - (float) $this->quantity_delivered;
        return number_format(max(0.0, $remaining), 2, '.', '');
    }
}
