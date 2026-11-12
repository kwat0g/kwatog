<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderAdvance extends Model
{
    use HasAuditLog, HasHashId;

    protected $fillable = [
        'sales_order_id', 'customer_id', 'attempt_number', 'order_total_snapshot', 'required_amount', 'percentage',
        'submitted_amount', 'refunded_amount', 'status', 'configured_by', 'submitted_by', 'sending_bank',
        'reference_number', 'reference_normalized', 'payment_date', 'proof_path', 'proof_file_name', 'proof_mime',
        'proof_size', 'proof_sha256', 'idempotency_key', 'request_fingerprint', 'verified_by', 'verified_at',
        'reviewed_by', 'reviewed_at', 'review_reason', 'journal_entry_id', 'refund_journal_entry_id',
        'refund_reference', 'refund_reason',
    ];

    protected $casts = [
        'order_total_snapshot' => 'decimal:2', 'required_amount' => 'decimal:2', 'percentage' => 'decimal:2',
        'submitted_amount' => 'decimal:2', 'refunded_amount' => 'decimal:2', 'payment_date' => 'date',
        'proof_size' => 'integer', 'verified_at' => 'datetime', 'reviewed_at' => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalUser::class, 'submitted_by');
    }

    public function configurator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function refundJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'refund_journal_entry_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(SalesOrderAdvanceApplication::class);
    }
}
