<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_request_id', 'item_id', 'description',
        'quantity', 'unit', 'estimated_unit_price', 'purpose',
    ];

    protected $casts = [
        'quantity'             => 'decimal:2',
        'estimated_unit_price' => 'decimal:2',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function getEstimatedTotalAttribute(): string
    {
        $qty   = (float) $this->quantity;
        $price = (float) ($this->estimated_unit_price ?? 0);
        return number_format($qty * $price, 2, '.', '');
    }
}
