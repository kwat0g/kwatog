<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasApprovalWorkflow;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierShipment;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\SupplyChain\Enums\Incoterm;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasApprovalWorkflow, HasAuditLog, HasFactory, HasHashId, SoftDeletes;

    protected static function newFactory(): PurchaseOrderFactory
    {
        return PurchaseOrderFactory::new();
    }

    protected $fillable = [
        'po_number', 'vendor_id', 'purchase_request_id',
        'request_for_quote_id',
        'date', 'expected_delivery_date', 'confirmed_delivery_date',
        'subtotal', 'vat_amount', 'total_amount', 'is_vatable',
        'rfq_vat_amount', 'rfq_freight_amount', 'rfq_other_charges',
        'requires_vp_approval',
        'created_by', 'remarks', 'incoterm',
        'is_auto_generated',
        'budget_warning_level', 'budget_warning_message',
        'budget_acknowledged_by', 'budget_acknowledged_at',
    ];

    protected $casts = [
        'date' => 'date',
        'expected_delivery_date' => 'date',
        'confirmed_delivery_date' => 'date',
        'budget_acknowledged_at' => 'datetime',
        'short_closed_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'rfq_vat_amount' => 'decimal:2',
        'rfq_freight_amount' => 'decimal:2',
        'rfq_other_charges' => 'decimal:2',
        'is_vatable' => 'boolean',
        'status' => PurchaseOrderStatus::class,
        'requires_vp_approval' => 'boolean',
        'current_approval_step' => 'integer',
        'approved_at' => 'datetime',
        'sent_to_supplier_at' => 'datetime',
        'is_auto_generated' => 'boolean',
        'incoterm' => Incoterm::class,
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(RequestForQuote::class, 'request_for_quote_id');
    }

    public function rfqQuoteReconfirmation(): HasOne
    {
        return $this->hasOne(RfqQuoteReconfirmation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * The receipt-derived status, or $fallback when nothing has landed.
     * SupplierProposed is receivable, so goods can arrive before a proposal
     * is resolved; restoring a fixed status then would hide the receipt.
     * Same rule as GrnService::refreshPoStatus().
     */
    public function receiptStatusOr(PurchaseOrderStatus $fallback): PurchaseOrderStatus
    {
        $lines = $this->items()->get(['quantity', 'quantity_received', 'quantity_accepted']);
        if ($lines->isNotEmpty() && $lines->every(
            fn (PurchaseOrderItem $l): bool => bccomp((string) $l->quantity_accepted, (string) $l->quantity, 3) >= 0
        )) {
            return PurchaseOrderStatus::Received;
        }
        if ($lines->contains(fn (PurchaseOrderItem $l): bool => bccomp((string) $l->quantity_received, '0', 3) > 0)) {
            return PurchaseOrderStatus::PartiallyReceived;
        }

        return $fallback;
    }

    public function goodsReceiptNotes(): HasMany
    {
        return $this->hasMany(GoodsReceiptNote::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(PurchaseOrderResponse::class);
    }

    /**
     * The most recent supplier response. `latestOfMany()` keys on the primary
     * key, so a re-submission (newer id) is the row consumers should read —
     * superseded replies stay reachable through `responses()`.
     */
    public function latestResponse(): HasOne
    {
        return $this->hasOne(PurchaseOrderResponse::class)->latestOfMany();
    }

    /**
     * Short-close ends a PO whose goods arrived but whose balance will never
     * come. Its eligibility is the exact complement of cancel(): cancel refuses
     * once a GRN has left draft, so a PO whose only receipt was rejected in
     * full (status back to sent/approved) must be short-closable, or it is
     * stranded open forever.
     */
    public function isShortClosable(): bool
    {
        // Status PartiallyReceived is always short-closable
        if ($this->status === PurchaseOrderStatus::PartiallyReceived) {
            return true;
        }

        // For Approved, Sent, Acknowledged: true only if at least one non-draft GRN exists
        if (in_array($this->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent, PurchaseOrderStatus::Acknowledged], true)) {
            if ($this->relationLoaded('goodsReceiptNotes')) {
                return $this->goodsReceiptNotes->some(
                    fn (GoodsReceiptNote $grn): bool => $grn->status !== GrnStatus::Draft
                );
            }
            return $this->goodsReceiptNotes()
                ->where('status', '!=', GrnStatus::Draft->value)
                ->exists();
        }

        return false;
    }

    /**
     * The pending change response, if any. A price-increase proposal that
     * was accepted but requires re-approval stays linked here while the
     * approval chain re-runs. Once approved, applied, and committed to
     * 'acknowledged', this is cleared.
     */
    public function pendingChangeResponse(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderResponse::class, 'pending_change_response_id');
    }

    public function supplierDispatch(): HasOne
    {
        return $this->hasOne(SupplierOrderDispatch::class);
    }

    public function supplierShipment(): HasOne
    {
        return $this->hasOne(SupplierShipment::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function budgetAcknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'budget_acknowledged_by');
    }

    public function shortClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'short_closed_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        // One definition of "open" for every consumer. Adding a status to the
        // enum's open() set updates all of them together (dashboards, MRP
        // in-transit, warehouse queue).
        return $q->whereIn('status', PurchaseOrderStatus::open());
    }

    public function getQuantityReceivedPercentAttribute(): float
    {
        $totalOrdered = (float) $this->items()->sum('quantity');
        $totalReceived = (float) $this->items()->sum('quantity_received');
        if ($totalOrdered <= 0) {
            return 0.0;
        }

        return round(($totalReceived / $totalOrdered) * 100, 2);
    }

    public function getQuantityAcceptedPercentAttribute(): float
    {
        $ordered = (float) $this->items()->sum('quantity');
        $accepted = (float) $this->items()->sum('quantity_accepted');

        return $ordered > 0 ? round(($accepted / $ordered) * 100, 2) : 0.0;
    }
}
