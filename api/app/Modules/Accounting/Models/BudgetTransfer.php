<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetTransfer extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'transfer_number',
        'from_line_item_id',
        'to_line_item_id',
        'month',
        'amount',
        'reason',
        'requested_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function fromLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLineItem::class, 'from_line_item_id');
    }

    public function toLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLineItem::class, 'to_line_item_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
