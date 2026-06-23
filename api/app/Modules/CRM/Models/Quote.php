<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'quote_number',
        'opportunity_id',
        'customer_id',
        'status',
        'valid_until',
        'subtotal',
        'tax_amount',
        'total_amount',
        'terms',
        'converted_to_sales_order_id',
        'revision',
    ];

    protected $casts = [
        'status'      => QuoteStatus::class,
        'valid_until' => 'date',
        'subtotal'    => 'decimal:2',
        'tax_amount'  => 'decimal:2',
        'total_amount'=> 'decimal:2',
        'revision'    => 'integer',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'converted_to_sales_order_id');
    }
}
