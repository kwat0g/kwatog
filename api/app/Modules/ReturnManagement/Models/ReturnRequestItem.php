<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequestItem extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'return_request_id',
        'product_id',
        'item_id',
        'quantity',
        'returned_quantity',
        'unit_price',
        'total',
        'reason',
        'condition',
        'stock_movement_quantity',
        'source_sales_order_item_id',
        'source_invoice_item_id',
        'source_po_item_id',
        'source_bill_item_id',
    ];

    protected $casts = [
        'quantity'               => 'decimal:3',
        'returned_quantity'      => 'decimal:3',
        'unit_price'             => 'decimal:2',
        'total'                  => 'decimal:2',
        'stock_movement_quantity' => 'decimal:3',
    ];

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
