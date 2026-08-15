<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected static function newFactory(): \Database\Factories\BillFactory
    {
        return \Database\Factories\BillFactory::new();
    }

    protected $fillable = [
        'bill_number', 'vendor_id', 'purchase_order_id', 'goods_receipt_note_id', 'provenance_type', 'exception_evidence', 'exception_owner_id', 'exception_approved_by', 'exception_approved_at',
        'date', 'due_date', 'is_vatable',
        'subtotal', 'vat_amount', 'total_amount', 'amount_paid', 'balance',
        'status', 'journal_entry_id', 'created_by', 'remarks',
        'has_variances', 'three_way_match_snapshot',
        'three_way_overridden', 'three_way_overridden_by',
        'three_way_overridden_at', 'three_way_override_reason',
    ];

    protected $casts = [
        'date'                     => 'date',
        'due_date'                 => 'date',
        'is_vatable'               => 'boolean',
        'subtotal'                 => 'decimal:2',
        'vat_amount'               => 'decimal:2',
        'total_amount'             => 'decimal:2',
        'amount_paid'              => 'decimal:2',
        'balance'                  => 'decimal:2',
        'status'                   => BillStatus::class,
        'has_variances'            => 'boolean',
        'three_way_match_snapshot' => 'array',
        'three_way_overridden'     => 'boolean',
        'three_way_overridden_at'  => 'datetime', 'exception_approved_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Purchasing\Models\PurchaseOrder::class);
    }

    /** 2026-08-08 — the goods receipt this bill was auto-created from (if any). */
    public function goodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Inventory\Models\GoodsReceiptNote::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class)->orderBy('payment_date');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial]);
    }

    /**
     * Derived workflow state for a PO-linked bill. Keeping this derived from
     * the persisted match snapshot avoids another mutable status column while
     * making the manual-review transition explicit to API consumers.
     */
    public function threeWayReviewStatus(): string
    {
        if (! $this->purchase_order_id) {
            return 'not_applicable';
        }
        if ($this->three_way_overridden) {
            return 'overridden';
        }
        if (data_get($this->three_way_match_snapshot, 'overall_status') === 'blocked') {
            return 'manual_review';
        }
        if ($this->has_variances) {
            return 'within_tolerance';
        }

        return 'matched';
    }

    public function isOverdue(): bool
    {
        if ($this->status === BillStatus::Paid || $this->status === BillStatus::Cancelled || $this->status === BillStatus::Draft) return false;
        return $this->due_date && $this->due_date->isPast();
    }

    public function agingBucket(?\Carbon\Carbon $asOf = null): string
    {
        $asOf = $asOf ?? now();
        if ($this->status === BillStatus::Paid || $this->status === BillStatus::Cancelled || $this->status === BillStatus::Draft) return 'paid';
        if (! $this->due_date || $this->due_date->gte($asOf)) return 'current';
        $days = $this->due_date->diffInDays($asOf);
        return match (true) {
            $days <= 30  => 'd1_30',
            $days <= 60  => 'd31_60',
            $days <= 90  => 'd61_90',
            default      => 'd91_plus',
        };
    }
}
