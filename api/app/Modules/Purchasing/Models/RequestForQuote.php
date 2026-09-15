<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\RfqStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestForQuote extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'rfq_number', 'purchase_request_id', 'created_by', 'title', 'instructions',
        'currency', 'issued_at', 'closes_at', 'cancellation_reason', 'last_extension_reason', 'no_award_reason',
        'budget_warning_level', 'budget_warning_message', 'budget_acknowledged_by',
        'budget_acknowledged_at',
    ];

    protected $casts = [
        'status' => RfqStatus::class,
        'issued_at' => 'datetime',
        'closes_at' => 'datetime',
        'closed_at' => 'datetime',
        'evaluation_started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'budget_acknowledged_at' => 'datetime',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function budgetAcknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'budget_acknowledged_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestForQuoteItem::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(RequestForQuoteInvitation::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(SupplierQuote::class);
    }

    public function awards(): HasMany
    {
        return $this->hasMany(RfqAward::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'request_for_quote_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RfqDocument::class);
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(RfqAddendum::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', RfqStatus::active());
    }

    public function isClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->isPast();
    }
}
