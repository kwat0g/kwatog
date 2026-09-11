<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Enums\SalesOrderSubmissionSource;
use App\Modules\SupplyChain\Enums\Incoterm;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected static function newFactory(): \Database\Factories\SalesOrderFactory
    {
        return \Database\Factories\SalesOrderFactory::new();
    }

    protected $fillable = [
        'so_number', 'customer_id', 'sales_rep_id', 'date', 'subtotal', 'vat_amount',
        'total_amount', 'status', 'payment_terms_days', 'delivery_terms',
        'notes', 'submission_source', 'mrp_plan_id', 'created_by', 'incoterm', 'confirmed_at',
        'customer_confirmation_requested_at',
        'in_production_at', 'partially_delivered_at', 'delivered_at',
        'invoiced_at', 'cancelled_at',
    ];

    protected $casts = [
        'date'               => 'date',
        'subtotal'           => 'decimal:2',
        'vat_amount'         => 'decimal:2',
        'total_amount'       => 'decimal:2',
        'status'             => SalesOrderStatus::class,
        'submission_source'  => SalesOrderSubmissionSource::class,
        'payment_terms_days' => 'integer',
        'mrp_plan_id'        => 'integer',
        'incoterm'           => Incoterm::class,
        'confirmed_at'       => 'datetime',
        'customer_confirmation_requested_at' => 'datetime',
        'in_production_at'   => 'datetime',
        'partially_delivered_at' => 'datetime',
        'delivered_at'       => 'datetime',
        'invoiced_at'        => 'datetime',
        'cancelled_at'       => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    /**
     * Sprint 6 audit §3.2: relations consumed by the right-panel
     * LinkedRecords on the detail page.
     */
    public function mrpPlan(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MRP\Models\MrpPlan::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(\App\Modules\Production\Models\WorkOrder::class);
    }

    /** Relationship consumed by B2B Customer Portal order detail. */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /** Relationship consumed by B2B Customer Portal order detail. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'sales_order_id');
    }

    /** Every customer negotiation reply, newest last. */
    public function responses(): HasMany
    {
        return $this->hasMany(SalesOrderResponse::class);
    }

    /**
     * The most recent customer response. `latestOfMany()` keys on the primary
     * key, so a re-submission (newer id) is the row consumers should read —
     * superseded replies stay reachable through `responses()`.
     */
    public function latestResponse(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SalesOrderResponse::class)->latestOfMany();
    }

    /**
     * True while the customer has been asked to review a still-draft order and
     * may reply. Capability hints on the resource read this, and the customer
     * response service enforces it.
     */
    public function getIsOpenToCustomerResponseAttribute(): bool
    {
        return $this->status === SalesOrderStatus::Draft
            && $this->customer_confirmation_requested_at !== null;
    }

    /** Scope used by list filters. */
    public function scopeStatus(Builder $q, SalesOrderStatus|string $status): Builder
    {
        return $q->where('status', $status instanceof SalesOrderStatus ? $status->value : $status);
    }

    public function getIsEditableAttribute(): bool
    {
        return $this->status === SalesOrderStatus::Draft;
    }

    public function getIsCancellableAttribute(): bool
    {
        if (! in_array($this->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed], true)) {
            return false;
        }

        // show() eager-loads these relations, so the UI does not advertise a
        // cancellation the service will reject because downstream work exists.
        if ($this->relationLoaded('deliveries') && $this->deliveries->contains(
            fn ($delivery): bool => (string) ($delivery->status?->value ?? $delivery->status) !== 'cancelled',
        )) {
            return false;
        }
        if ($this->relationLoaded('invoices') && $this->invoices->contains(
            fn ($invoice): bool => (string) ($invoice->status?->value ?? $invoice->status) !== 'cancelled',
        )) {
            return false;
        }
        if ($this->relationLoaded('workOrders') && $this->workOrders->contains(
            fn ($workOrder): bool => in_array(
                (string) ($workOrder->status?->value ?? $workOrder->status),
                ['in_progress', 'completed', 'closed'],
                true,
            ),
        )) {
            return false;
        }

        return true;
    }
}
