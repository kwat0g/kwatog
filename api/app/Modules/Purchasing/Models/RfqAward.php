<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RfqAward extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'request_for_quote_id', 'request_for_quote_item_id', 'supplier_quote_id',
        'supplier_quote_item_id', 'vendor_id', 'awarded_quantity', 'awarded_unit_price',
        'awarded_total_delivered_cost', 'award_reason', 'awarded_by', 'awarded_at',
    ];

    protected $casts = [
        'awarded_quantity' => 'decimal:4',
        'awarded_unit_price' => 'decimal:4',
        'awarded_total_delivered_cost' => 'decimal:2',
        'awarded_at' => 'datetime',
    ];

    public function rfq(): BelongsTo { return $this->belongsTo(RequestForQuote::class, 'request_for_quote_id'); }
    public function rfqItem(): BelongsTo { return $this->belongsTo(RequestForQuoteItem::class, 'request_for_quote_item_id'); }
    public function quote(): BelongsTo { return $this->belongsTo(SupplierQuote::class, 'supplier_quote_id'); }
    public function quoteItem(): BelongsTo { return $this->belongsTo(SupplierQuoteItem::class, 'supplier_quote_item_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function awarder(): BelongsTo { return $this->belongsTo(User::class, 'awarded_by'); }

    /** The PO line generated for this award (one per award). */
    public function purchaseOrderItem(): HasOne { return $this->hasOne(PurchaseOrderItem::class, 'rfq_award_id'); }
}
