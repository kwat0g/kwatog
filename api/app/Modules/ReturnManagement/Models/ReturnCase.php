<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\ReturnManagement\Enums\ReturnCasePreferredResolution;
use App\Modules\ReturnManagement\Enums\ReturnCaseResolution;
use App\Modules\ReturnManagement\Enums\ReturnCaseStatus;
use App\Modules\ReturnManagement\Enums\ReturnCaseType;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Return intake and follow-up record. RMA, inventory, and accounting documents
 * remain the authorities for physical returns, movements, and settlement.
 */
class ReturnCase extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    /** Only customer-submitted intake values are mass assignable. Lifecycle,
     *  ownership, provenance, idempotency, and settlement are service-owned. */
    protected $fillable = [
        'description',
        'preferred_resolution',
    ];

    protected $casts = [
        'type' => ReturnCaseType::class,
        'intake_kind' => \App\Modules\ReturnManagement\Enums\ReturnCaseIntakeKind::class,
        'status' => ReturnCaseStatus::class,
        'preferred_resolution' => ReturnCasePreferredResolution::class,
        'resolution' => ReturnCaseResolution::class,
        'expected_date' => 'date',
        'resolved_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customerPortalUser(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalUser::class, 'customer_portal_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function replacementSalesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'replacement_sales_order_id');
    }

    public function replacementPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'replacement_purchase_order_id');
    }

    public function replacementDelivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'replacement_delivery_id');
    }

    public function resolutionGoodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class, 'resolution_goods_receipt_note_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnCaseLine::class)->orderBy('id');
    }

    public function receiptAllocations(): HasMany
    {
        return $this->hasMany(ReturnCaseReceiptAllocation::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReturnCaseEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReturnCaseAttachment::class)->orderBy('created_at')->orderBy('id');
    }
}
