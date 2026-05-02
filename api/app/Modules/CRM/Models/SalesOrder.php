<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'so_number', 'customer_id', 'date', 'subtotal', 'vat_amount',
        'total_amount', 'status', 'payment_terms_days', 'delivery_terms',
        'notes', 'mrp_plan_id', 'created_by',
    ];

    protected $casts = [
        'date'               => 'date',
        'subtotal'           => 'decimal:2',
        'vat_amount'         => 'decimal:2',
        'total_amount'       => 'decimal:2',
        'status'             => SalesOrderStatus::class,
        'payment_terms_days' => 'integer',
        'mrp_plan_id'        => 'integer',
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
        return ! in_array($this->status, [
            SalesOrderStatus::Delivered,
            SalesOrderStatus::Invoiced,
            SalesOrderStatus::Cancelled,
        ], true);
    }
}
