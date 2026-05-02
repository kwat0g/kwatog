<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'invoice_number', 'customer_id', 'sales_order_id', 'delivery_id',
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
        'status'       => InvoiceStatus::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class)->orderBy('collection_date');
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
        return $q->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial]);
    }

    public function isDraft(): bool { return $this->status === InvoiceStatus::Draft; }

    public function isOverdue(): bool
    {
        if (in_array($this->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled, InvoiceStatus::Draft], true)) return false;
        return $this->due_date && $this->due_date->isPast();
    }

    public function agingBucket(?\Carbon\Carbon $asOf = null): string
    {
        $asOf = $asOf ?? now();
        if (in_array($this->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled, InvoiceStatus::Draft], true)) {
            return $this->status->value;
        }
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
