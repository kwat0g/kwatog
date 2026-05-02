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

    protected $fillable = [
        'bill_number', 'vendor_id', 'purchase_order_id',
        'date', 'due_date', 'is_vatable',
        'subtotal', 'vat_amount', 'total_amount', 'amount_paid', 'balance',
        'status', 'journal_entry_id', 'created_by', 'remarks',
    ];

    protected $casts = [
        'date'         => 'date',
        'due_date'     => 'date',
        'is_vatable'   => 'boolean',
        'subtotal'     => 'decimal:2',
        'vat_amount'   => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid'  => 'decimal:2',
        'balance'      => 'decimal:2',
        'status'       => BillStatus::class,
    ];

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

    public function isOverdue(): bool
    {
        if ($this->status === BillStatus::Paid || $this->status === BillStatus::Cancelled) return false;
        return $this->due_date && $this->due_date->isPast();
    }

    public function agingBucket(?\Carbon\Carbon $asOf = null): string
    {
        $asOf = $asOf ?? now();
        if ($this->status === BillStatus::Paid || $this->status === BillStatus::Cancelled) return 'paid';
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
