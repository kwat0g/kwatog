<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetRevision extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'budget_id',
        'revision_number',
        'changes',
        'reason',
        'submitted_by',
        'approved_by',
        'status',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'approved_by');
    }
}
