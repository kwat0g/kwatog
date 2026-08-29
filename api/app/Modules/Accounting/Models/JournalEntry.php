<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Support\HashId;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Support\JournalEntryAuditContext;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'entry_number', 'date', 'description',
        'reference_type', 'reference_id',
        'total_debit', 'total_credit',
        'status',
        'reversal_reason',
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

    /** @return array{user_id:?int, actor_type:string, reason:?string}|null */
    public function auditContextOverride(): ?array
    {
        return JournalEntryAuditContext::current();
    }

    /**
     * Human label for the linked source record, used by the API resource.
     *
     * The identifier is HASHED. This used to interpolate `$this->reference_id`
     * directly — "Bill #123", "Reversal of JE #21" — one key below the same
     * resource's carefully hashed `reference_id`, so a single response handed a
     * client both halves of the mapping and the obfuscation bought nothing.
     * `class_basename` is applied for the same reason the raw id was removed:
     * two allow-listed reference types are literal model class names
     * (Asset::class, Clearance::class), and a finance officer was shown
     * "App\Modules\Assets\Models\Asset #123".
     *
     * A hash is not a document number, which is what an operator actually wants
     * here — resolving each source family to its own number needs a per-family
     * eager-load and is tracked separately. This method's contract is only that
     * it never leaks an internal identifier.
     */
    public function referenceLabel(): ?string
    {
        if (! $this->reference_type) {
            return null;
        }

        $noun = match ($this->reference_type) {
            'payroll_period'         => 'Payroll Period',
            'bill'                   => 'Bill',
            'bill_payment'           => 'Bill Payment',
            'invoice'                => 'Invoice',
            'collection'             => 'Collection',
            'credit_note'            => 'Credit Note',
            'journal_entry_reversal' => 'Reversal of JE',
            default                  => ucfirst(str_replace(
                '_', ' ', class_basename($this->reference_type),
            )),
        };

        // Types such as `asset_depreciation` and `opening` are allow-listed with
        // a null id by design, so the noun stands alone rather than trailing a
        // dangling separator.
        if ($this->reference_id === null) {
            return $noun;
        }

        return $noun.' '.HashId::encode((int) $this->reference_id);
    }
}
