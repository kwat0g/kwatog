<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * WS-A.1 — One-time, expiring invite that links an employee to a freshly
 * created portal user.
 */
class UserInvite extends Model
{
    use HasFactory, HasHashId, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'email',
        'token',
        'role_id',
        'expires_at',
        'used_at',
        'invited_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    protected $hidden = [
        // Token is generally exposed only at creation; never include it in
        // listing responses.
        'token',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isUsed() && ! $this->isExpired() && $this->deleted_at === null;
    }
}
