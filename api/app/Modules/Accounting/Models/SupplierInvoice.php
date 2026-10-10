<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasHashId;
use App\Common\Traits\HasAuditLog;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoice extends Model
{
    use HasHashId, HasAuditLog;

    protected $fillable = [
        'vendor_id', 'purchase_order_id', 'number', 'date', 'gross_total', 'remarks',
        'submitted_at', 'portal_user_id', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'date' => 'date',
        'gross_total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Purchasing\Models\PurchaseOrder::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(\App\Modules\B2B\Models\PortalShippingDocument::class);
    }

    public function matchesLinkedBills(): bool
    {
        if ($this->bills()->where('status', BillStatus::Cancelled->value)->exists()) {
            return false;
        }

        $totals = $this->bills()->pluck('total_amount');
        if ($totals->isEmpty()) {
            return false;
        }

        $sum = $totals->reduce(
            static fn (string $carry, mixed $total): string => Money::add($carry, (string) $total),
            Money::zero(),
        );

        return Money::cmp($sum, (string) $this->gross_total) === 0;
    }
}
