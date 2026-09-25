<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnReceiptItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_receipt_id',
        'return_request_item_id',
        'quantity',
        'stock_movement_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ReturnReceipt::class, 'return_receipt_id');
    }

    public function returnRequestItem(): BelongsTo
    {
        return $this->belongsTo(ReturnRequestItem::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
