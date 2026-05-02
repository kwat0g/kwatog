<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasApprovalWorkflow;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory, HasHashId, HasAuditLog, HasApprovalWorkflow;

    protected $fillable = [
        'po_number', 'vendor_id', 'purchase_request_id',
        'date', 'expected_delivery_date',
        'subtotal', 'vat_amount', 'total_amount', 'is_vatable',
        'status', 'requires_vp_approval', 'current_approval_step',
        'approved_by', 'approved_at', 'sent_to_supplier_at',
        'created_by', 'remarks',
    ];

    protected $casts = [
        'date'                   => 'date',
        'expected_delivery_date' => 'date',
        'subtotal'               => 'decimal:2',
        'vat_amount'             => 'decimal:2',
        'total_amount'           => 'decimal:2',
        'is_vatable'             => 'boolean',
        'status'                 => PurchaseOrderStatus::class,
        'requires_vp_approval'   => 'boolean',
        'current_approval_step'  => 'integer',
        'approved_at'            => 'datetime',
        'sent_to_supplier_at'    => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceiptNotes(): HasMany
    {
        return $this->hasMany(GoodsReceiptNote::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::Sent,
            PurchaseOrderStatus::PartiallyReceived,
        ]);
    }

    public function getQuantityReceivedPercentAttribute(): float
    {
        $totalOrdered = 0.0;
        $totalReceived = 0.0;
        foreach ($this->items as $line) {
            $totalOrdered  += (float) $line->quantity;
            $totalReceived += (float) $line->quantity_received;
        }
        if ($totalOrdered <= 0) return 0.0;
        return round(($totalReceived / $totalOrdered) * 100, 2);
    }
}
