<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Enums\CreditNoteStatus;
use App\Modules\Accounting\Enums\CreditNoteType;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * REC-13 — AR/AP credit note. See migration 0268.
 * `status` is not fillable (lifecycle managed by CreditNoteService).
 */
class CreditNote extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'credit_note_number', 'type', 'customer_id', 'vendor_id',
        'invoice_id', 'bill_id', 'return_request_id',
        'date', 'is_vatable', 'subtotal', 'vat_amount', 'total_amount',
        'applied_amount', 'balance', 'reason', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'type'           => CreditNoteType::class,
        'status'         => CreditNoteStatus::class,
        'date'           => 'date',
        'is_vatable'     => 'boolean',
        'subtotal'       => 'decimal:2',
        'vat_amount'     => 'decimal:2',
        'total_amount'   => 'decimal:2',
        'applied_amount' => 'decimal:2',
        'balance'        => 'decimal:2',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function bill(): BelongsTo { return $this->belongsTo(Bill::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function lines(): HasMany { return $this->hasMany(CreditNoteLine::class); }
    public function applications(): HasMany { return $this->hasMany(CreditNoteApplication::class); }
}
