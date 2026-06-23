<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReturnRequest extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'rma_number',
        'type',
        'status',
        'sales_order_id',
        'invoice_id',
        'purchase_order_id',
        'bill_id',
        'customer_id',
        'vendor_id',
        'reason_code',
        'reason_description',
        'customer_notes',
        'internal_notes',
        'resolution',
        'credit_note_id',
        'replacement_wo_id',
        'refund_amount',
        'stock_movement_id',
        'inspection_id',
        'ncr_id',
        'return_date',
        'approved_at',
        'received_at',
        'inspected_at',
        'completed_at',
        'rejected_at',
        'cancelled_at',
        'created_by',
        'approved_by',
        'completed_by',
    ];

    protected $casts = [
        'type'         => ReturnRequestType::class,
        'status'       => ReturnRequestStatus::class,
        'return_date'  => 'date',
        'refund_amount' => 'decimal:2',
        'approved_at'  => 'datetime',
        'received_at'  => 'datetime',
        'inspected_at' => 'datetime',
        'completed_at' => 'datetime',
        'rejected_at'  => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Accounting\Models\Bill::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'credit_note_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(NonConformanceReport::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function getIsEditableAttribute(): bool
    {
        return $this->status === ReturnRequestStatus::Draft;
    }

    public function getSourceLabelAttribute(): string
    {
        if ($this->type === ReturnRequestType::CustomerReturn) {
            if ($this->relationLoaded('invoice') && $this->invoice) {
                return "Invoice {$this->invoice->invoice_number}";
            }
            if ($this->relationLoaded('salesOrder') && $this->salesOrder) {
                return "SO {$this->salesOrder->so_number}";
            }
            if ($this->relationLoaded('customer') && $this->customer) {
                return $this->customer->name;
            }
            return '—';
        }
        if ($this->relationLoaded('bill') && $this->bill) {
            return "Bill {$this->bill->bill_number}";
        }
        if ($this->relationLoaded('purchaseOrder') && $this->purchaseOrder) {
            return "PO {$this->purchaseOrder->po_number}";
        }
        if ($this->relationLoaded('vendor') && $this->vendor) {
            return $this->vendor->name;
        }
        return '—';
    }
}
