<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetTransfer extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'from_budget_line_id',
        'to_budget_line_id',
        'amount',
        'reason',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'amount'      => 'decimal:2',
    ];

    public function fromLineItem(): BelongsTo
    {
        return $this->belongsTo(BudgetLineItem::class, 'from_budget_line_id');
    }

    public function toLineItem(): BelongsTo
    {
        return $this->belongsTo(BudgetLineItem::class, 'to_budget_line_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'approved_by');
    }
}
