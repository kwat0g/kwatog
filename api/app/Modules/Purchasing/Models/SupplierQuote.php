<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Enums\SupplierQuoteStatus;
use App\Modules\Purchasing\Enums\VatTreatment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierQuote extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

    protected $fillable = [
        'request_for_quote_id', 'vendor_id', 'invitation_id', 'portal_user_id', 'captured_by',
        'vat_treatment', 'vat_amount', 'freight_amount', 'total_delivered_cost',
        'quote_valid_until', 'payment_terms', 'notes', 'quotation_path', 'quotation_original_filename',
    ];

    protected $casts = [
        'status' => SupplierQuoteStatus::class,
        'vat_treatment' => VatTreatment::class,
        'submitted_at' => 'datetime',
        'vat_amount' => 'decimal:2',
        'freight_amount' => 'decimal:2',
        'total_delivered_cost' => 'decimal:2',
        'quote_valid_until' => 'date',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(RequestForQuote::class, 'request_for_quote_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(RequestForQuoteInvitation::class, 'invitation_id');
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(SupplierPortalUser::class, 'portal_user_id');
    }

    public function capturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierQuoteItem::class);
    }

    public function awards(): HasMany
    {
        return $this->hasMany(RfqAward::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RfqDocument::class, 'supplier_quote_id');
    }

    /** Quotes that take part in evaluation: submitted, or already resolved by the award. */
    public function scopeEvaluable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SupplierQuoteStatus::Submitted->value,
            SupplierQuoteStatus::Awarded->value,
            SupplierQuoteStatus::NotAwarded->value,
        ]);
    }

    public function isExpired(): bool
    {
        return $this->quote_valid_until !== null && $this->quote_valid_until->lt(today());
    }

    /**
     * Sealed-bid values never enter the audit trail: audit logs are readable
     * by administrators while the RFQ is still open. The quote row itself
     * keeps the final figures, which unseal with the RFQ.
     *
     * @return array<string, string>
     */
    public function auditAttributeSnapshot(): array
    {
        return array_fill_keys(['vat_amount', 'freight_amount', 'total_delivered_cost', 'payment_terms', 'notes'], '[sealed]');
    }
}
