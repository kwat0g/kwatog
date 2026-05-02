<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'entry_number', 'date', 'description',
        'reference_type', 'reference_id',
        'total_debit', 'total_credit',
        'status',
        'reversed_by_entry_id',
        'posted_at', 'posted_by',
        'created_by',
    ];

    protected $casts = [
        'date'         => 'date',
        'posted_at'    => 'datetime',
        'total_debit'  => 'decimal:2',
        'total_credit' => 'decimal:2',
        'status'       => JournalEntryStatus::class,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_no');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function reversal(): HasMany
    {
        return $this->hasMany(self::class, 'reversed_by_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function scopePosted(Builder $q): Builder
    {
        return $q->where('status', JournalEntryStatus::Posted);
    }

    public function scopeBetween(Builder $q, string $from, string $to): Builder
    {
        return $q->whereBetween('date', [$from, $to]);
    }

    public function isDraft(): bool    { return $this->status === JournalEntryStatus::Draft; }
    public function isPosted(): bool   { return $this->status === JournalEntryStatus::Posted; }
    public function isReversed(): bool { return $this->status === JournalEntryStatus::Reversed; }

    /**
     * Best-effort human label for the linked source record (used by the API resource).
     */
    public function referenceLabel(): ?string
    {
        if (! $this->reference_type) return null;
        return match ($this->reference_type) {
            'payroll_period'           => "Payroll Period #{$this->reference_id}",
            'bill'                     => "Bill #{$this->reference_id}",
            'bill_payment'             => "Bill Payment #{$this->reference_id}",
            'invoice'                  => "Invoice #{$this->reference_id}",
            'collection'               => "Collection #{$this->reference_id}",
            'journal_entry_reversal'   => "Reversal of JE #{$this->reference_id}",
            default                    => ucfirst(str_replace('_', ' ', $this->reference_type))
                                           . " #{$this->reference_id}",
        };
    }
}
