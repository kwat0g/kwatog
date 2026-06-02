<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileUpdateRequest extends Model
{
    use HasHashId;

    protected $fillable = [
        'employee_id',
        'requested_by',
        'status',
        'requires_finance',
        'changes',
        'note',
        'reviewed_by',
        'reviewed_at',
        'review_remarks',
        'finance_reviewed_by',
        'finance_reviewed_at',
        'finance_remarks',
    ];

    protected $casts = [
        'changes'             => 'array',
        'requires_finance'    => 'boolean',
        'reviewed_at'         => 'datetime',
        'finance_reviewed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function financeReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_reviewed_by');
    }
}
