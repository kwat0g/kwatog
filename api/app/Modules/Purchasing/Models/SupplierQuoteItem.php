<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Purchasing\Enums\SupplierQuoteResponseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierQuoteItem extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'supplier_quote_id', 'request_for_quote_item_id', 'offered_quantity', 'unit_price',
        'response_status', 'line_total_delivered_cost', 'lead_time_days', 'proposed_delivery_date',
    ];

    protected $casts = [
        'response_status' => SupplierQuoteResponseStatus::class,
        'offered_quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'line_total_delivered_cost' => 'decimal:2',
        'lead_time_days' => 'integer',
        'proposed_delivery_date' => 'date',
    ];

    public function quote(): BelongsTo { return $this->belongsTo(SupplierQuote::class, 'supplier_quote_id'); }
    public function rfqItem(): BelongsTo { return $this->belongsTo(RequestForQuoteItem::class, 'request_for_quote_item_id'); }
    public function awards(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(RfqAward::class); }

    /**
     * Sealed-bid values never enter the audit trail: audit logs are readable
     * by administrators while the RFQ is still open. The quote row itself
     * keeps the final figures, which unseal with the RFQ.
     *
     * @return array<string, string>
     */
    public function auditAttributeSnapshot(): array
    {
        return array_fill_keys(['offered_quantity', 'unit_price', 'line_total_delivered_cost', 'lead_time_days', 'proposed_delivery_date'], '[sealed]');
    }
}
