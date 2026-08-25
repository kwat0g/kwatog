<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Support\Money;
use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'purchase_request_id', 'item_id', 'description',
        'quantity', 'unit', 'estimated_unit_price', 'purpose',
        'suggested_vendor_id',
    ];

    protected $casts = [
        'quantity'             => 'decimal:2',
        'estimated_unit_price' => 'decimal:2',
    ];

    public function suggestedVendor(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Accounting\Models\Vendor::class, 'suggested_vendor_id');
    }

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
        return Money::mul(
            (string) $this->quantity,
            (string) ($this->estimated_unit_price ?? '0'),
        );
    }
}
