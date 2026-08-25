<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Enums\BillPaymentStatus;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillPayment extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'bill_id', 'cash_account_id', 'payment_date',
        'amount', 'payment_method', 'reference_number',
        'journal_entry_id', 'created_by', 'status', 'voided_at', 'voided_by',
        'void_reason', 'void_reversal_journal_entry_id', 'replacement_payment_id',
    ];

    protected $casts = [
        'payment_date'   => 'date',
        'amount'         => 'decimal:2',
        'payment_method' => PaymentMethod::class,
        'status'         => BillPaymentStatus::class,
        'voided_at'      => 'datetime',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function voidReversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'void_reversal_journal_entry_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function replacementPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_payment_id');
    }
}
