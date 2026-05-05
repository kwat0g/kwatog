<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRecord extends Model
{
    use \App\Common\Traits\HasHashId;

    public $timestamps = false;
    protected $fillable = [
        'approvable_type', 'approvable_id',
        'step_order', 'role_slug',
        'approver_id', 'action', 'remarks', 'acted_at', 'created_at',
        // Task A7
        'reminder_sent_at', 'escalated_at', 'escalated_to_user_id',
    ];
    protected $casts = [
        'acted_at'         => 'datetime',
        'created_at'       => 'datetime',
        'reminder_sent_at' => 'datetime',
        'escalated_at'     => 'datetime',
    ];

    public function getIsOverdueAttribute(): bool
    {
        return $this->action === 'pending'
            && $this->created_at
            && $this->created_at->lt(now()->subHours(24));
    }

    public function getOverdueHoursAttribute(): int
    {
        if (! $this->is_overdue) return 0;
        return (int) abs(now()->diffInHours($this->created_at));
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'approver_id');
    }
}
