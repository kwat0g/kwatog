<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OGAMI-008 — BIR Official Receipt.
 *
 * Acknowledges actual cash received against an invoice/collection. Distinct
 * from the Sales Invoice (which records the sale); BIR requires both.
 */
class OfficialReceipt extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'or_number', 'invoice_id', 'collection_id', 'customer_id',
        'amount', 'date', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date'   => 'date',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
