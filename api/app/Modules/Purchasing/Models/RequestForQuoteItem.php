<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestForQuoteItem extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'request_for_quote_id', 'purchase_request_item_id', 'item_id', 'description',
        'specification', 'quantity', 'unit', 'required_delivery_date',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'required_delivery_date' => 'date',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(RequestForQuote::class, 'request_for_quote_id');
    }

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function quoteItems(): HasMany
    {
        return $this->hasMany(SupplierQuoteItem::class);
    }

    public function awards(): HasMany
    {
        return $this->hasMany(RfqAward::class);
    }
}
